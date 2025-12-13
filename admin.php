<?php
include 'include/db.php';
include 'functions.php';
// --- 1. معالجة إرجاع منتج (Return Item) ---
if (isset($_POST['return_item'])) {
    $itemId = $_POST['item_id'];
    // جلب التفاصيل
    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if ($item && $item['item_status'] == 'valid') {
        // تحويل الحالة لمرتجع
        $pdo->prepare("UPDATE order_items SET item_status = 'returned' WHERE id = ?")->execute([$itemId]);
        // إعادة الكمية للمخزون
        $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE name = ? AND quantity IS NOT NULL")->execute([$item['qty'], $item['product_name']]);
        
        echo "<script>alert('تم إرجاع المنتج للمخزون واستبعاده من الحسابات');</script>";
    }
    // العودة لنفس الطلب
    header("Location: admin.php?tab=orders&manage_order=" . $_POST['order_id']); 
    exit;
}

// --- 2. منطق التقارير (Reports Logic) ---
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // من بداية الشهر
$dateTo = $_GET['date_to'] ?? date('Y-m-d'); // إلى اليوم

// إحصائيات عامة (فقط للطلبات المجهزة/الموصلة + المنتجات غير المرتجعة)
$reportSql = "SELECT 
                COUNT(DISTINCT o.id) as total_orders,
                SUM(oi.price * oi.qty) as total_revenue,
                SUM(oi.qty) as total_items_sold
              FROM orders o
              JOIN order_items oi ON o.id = oi.order_id
              WHERE o.status IN ('processing', 'shipping', 'delivered')
              AND oi.item_status = 'valid'
              AND DATE(o.created_at) BETWEEN ? AND ?";
$repStmt = $pdo->prepare($reportSql);
$repStmt->execute([$dateFrom, $dateTo]);
$reportData = $repStmt->fetch(PDO::FETCH_ASSOC);

// المنتجات الأكثر مبيعاً
$topSql = "SELECT oi.product_name, SUM(oi.qty) as sold_qty 
           FROM order_items oi 
           JOIN orders o ON oi.order_id = o.id
           WHERE o.status IN ('processing', 'shipping', 'delivered') 
           AND oi.item_status = 'valid'
           AND DATE(o.created_at) BETWEEN ? AND ?
           GROUP BY oi.product_name 
           ORDER BY sold_qty DESC LIMIT 5";
$topStmt = $pdo->prepare($topSql);
$topStmt->execute([$dateFrom, $dateTo]);
$topProducts = $topStmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. جلب تفاصيل طلب معين (عند الضغط على زر التفاصيل) ---
$manageOrder = null;
if (isset($_GET['manage_order'])) {
    $mOid = $_GET['manage_order'];
    $moStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?"); $moStmt->execute([$mOid]);
    $manageOrder = $moStmt->fetch(PDO::FETCH_ASSOC);
    if($manageOrder) {
        $moiStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?"); $moiStmt->execute([$mOid]);
        $manageOrderItems = $moiStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    } else {
        // هذا السطر سيخبرك إذا كان المجلد ناقصاً
        die("خطأ: مجلد vendor غير موجود. تأكد من رفع المكتبة.");
    }
    use Minishlink\WebPush\WebPush;
    use Minishlink\WebPush\Subscription;
// === 1. إعدادات السيرفر ===
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// === 2. التحقق من الدخول ===
if (!isset($_SESSION['admin_id'])) {
    if (isset($_POST['login'])) {
        $email = trim($_POST['email']);
        $pass = trim($_POST['password']);
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($pass, $admin['password'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_email'] = $admin['email'];

            // --- التعديل الجديد: حفظ الصلاحية في الجلسة ---
            $_SESSION['is_super'] = $admin['is_super']; // 1 or 0
            // ----------------------------------------------

            if ($admin['must_change_password']) { header("Location: admin.php?action=change_pass"); exit; }
            header("Location: admin.php"); exit;
        } else { $error = "بيانات خاطئة"; }
    }
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>دخول</title><link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700&display=swap" rel="stylesheet"><style>body{font-family:"Almarai",sans-serif;background:#f4f4f4;height:100vh;display:flex;justify-content:center;align-items:center}form{background:#fff;padding:30px;border-radius:10px;box-shadow:0 4px 10px rgba(0,0,0,0.1);width:90%;max-width:400px;text-align:center}input{width:100%;padding:12px;margin:10px 0;border:1px solid #ddd;border-radius:5px;box-sizing:border-box}button{width:100%;padding:12px;background:#007bff;color:#fff;border:none;border-radius:5px;font-weight:bold}</style></head><body><form method="POST"><h2>دخول المشرف</h2><input type="email" name="email" placeholder="البريد" required><input type="password" name="password" placeholder="كلمة المرور" required><button name="login">دخول</button><p style="color:red">'.($error??'').'</p></form></body></html>';
    exit;
}

// === تحديد المشرف الرئيسي (بناءً على قاعدة البيانات الآن) ===
// هل هو مشرف عام (القيمة 1)؟
$isSuperAdmin = (isset($_SESSION['is_super']) && $_SESSION['is_super'] == 1);

// === 3. معالجة الطلبات (POST) ===
if (isset($_POST['delete_all_orders'])) {
    $pdo->exec("DELETE FROM orders"); $pdo->exec("ALTER TABLE orders AUTO_INCREMENT = 1"); $pdo->exec("ALTER TABLE order_items AUTO_INCREMENT = 1");
    header("Location: admin.php?tab=tab-orders&t=".time()); exit;
}
if (isset($_POST['update_order_status'])) {
    $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$_POST['status'], $_POST['order_id']]);
    header("Location: admin.php?tab=tab-orders&t=".time()); exit;
}
if (isset($_POST['add_category'])) { $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$_POST['cat_name']]); header("Location: admin.php?tab=tab-cats&t=".time()); exit; }
if (isset($_POST['delete_category'])) { $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$_POST['cat_id']]); header("Location: admin.php?tab=tab-cats&t=".time()); exit; }
if (isset($_POST['update_category'])) { $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?")->execute([$_POST['cat_name'], $_POST['cat_id']]); header("Location: admin.php?tab=tab-cats&t=".time()); exit; }

if (isset($_POST['add_product'])) {
    $name=$_POST['name']; $desc=$_POST['description']; $supplier=!empty($_POST['supplier'])?$_POST['supplier']:'غير محدد';
    $price=$_POST['price']; $qty=$_POST['qty']!==''?$_POST['qty']:null; $disc=!empty($_POST['discount'])?$_POST['discount']:null;
    $cat=$_POST['category']; $note=$_POST['note']; $sizes=!empty($_POST['sizes'])?$_POST['sizes']:null;
    if ($disc!==null && $disc>=$price) { echo "<script>alert('خطأ: سعر الخصم أكبر من الرسمي');window.history.back();</script>"; exit; }

    // --- Enhanced Image Upload Security ---
    if (!empty($_FILES['image']['name'])) {
        $validationResult = validateImageUpload($_FILES['image']);
        if ($validationResult !== true) {
            echo "<script>alert('$validationResult');window.history.back();</script>";
            exit;
        }

        // Process and optimize the image
        $originalImgName = time() . '_' . basename($_FILES['image']['name']);
        $newImgName = optimizeImage($_FILES['image']['tmp_name'], $originalImgName);
        if ($newImgName === false) {
            echo "<script>alert('خطأ في معالجة الصورة، قد يكون نوع الملف غير مدعوم.');window.history.back();</script>";
            exit;
        }

        $pdo->prepare("INSERT INTO products (name,description,supplier,sizes,category_id,price,discount_price,quantity,image,admin_note) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$name,$desc,$supplier,$sizes,$cat,$price,$disc,$qty,$newImgName,$note]);

    } else {
        echo "<script>alert('خطأ: يجب اختيار صورة للمنتج.');window.history.back();</script>";
        exit;
    }
    // ------------------------------------
    // =========================================
    if (class_exists('Minishlink\WebPush\WebPush')) {
        
        // 1. إعدادات المفاتيح (ضع مفاتيحك هنا)
        $auth = [
        'VAPID' => [
            'subject' => 'mailto:dev@sam.com', 
            'publicKey' => 'BAvM16qSKZq8JWSLwK5EFBixHA-d4uvzePvNzldMCNGf4OR3iXQ-kvWhNWqGlpTrNptDQ2PvSM0wigI7h8dfatc',
            'privateKey' => 'JbCc64c2T_Pf1zPLGiFo2h2wo_-frm8QscXhH3ewi4Y',
        ],
    ];

        try {
            $webPush = new WebPush($auth);

            // 2. جلب المشتركين
            $subs = $pdo->query("SELECT * FROM push_subscriptions")->fetchAll(PDO::FETCH_ASSOC);

            // 3. محتوى الرسالة
            $payload = json_encode([
                'title' => '🔥 منتج جديد: ' . $name,
                'body' => 'بسعر: ' . $price . ' ر.ي',
                'icon' => 'icons/icon-192x192.png',
                'url'  => 'https://sam.medo-hop.com/latest.php' 
            ]);

            // 4. الإرسال
            foreach ($subs as $sub) {
                $subscription = Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'publicKey' => $sub['p256dh'],
                    'authToken' => $sub['auth'],
                ]);
                $webPush->sendOneNotification($subscription, $payload);
            }
        } catch (Exception $e) {
            // في حال فشل الإشعار، لا توقف الموقع، فقط أكمل
            // يمكنك تسجيل الخطأ هنا إذا أردت
        }
    }
    
    // =========================================
  header("Location: admin.php?tab=tab-products&t=".time()); exit;
}
if (isset($_POST['delete_product'])) { $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$_POST['prod_id']]); header("Location: admin.php?tab=tab-products&t=".time()); exit; }
if (isset($_POST['update_product'])) {
    $id=$_POST['prod_id']; $name=$_POST['name']; $desc=$_POST['description']; $supplier=!empty($_POST['supplier'])?$_POST['supplier']:'غير محدد';
    $price=$_POST['price']; $qty=$_POST['qty']!==''?$_POST['qty']:null; $disc=!empty($_POST['discount'])?$_POST['discount']:null;
    $cat=$_POST['category']; $note=$_POST['note']; $sizes=!empty($_POST['sizes'])?$_POST['sizes']:null;
    if ($disc!==null && $disc>=$price) { echo "<script>alert('خطأ: سعر الخصم أكبر من الرسمي');window.history.back();</script>"; exit; }
    if (!empty($_FILES['image']['name'])) {
        $validationResult = validateImageUpload($_FILES['image']);
        if ($validationResult !== true) {
            echo "<script>alert('$validationResult');window.history.back();</script>";
            exit;
        }

        // Process and optimize the image
        $originalImgName = time() . '_' . basename($_FILES['image']['name']);
        $newImgName = optimizeImage($_FILES['image']['tmp_name'], $originalImgName);
        if ($newImgName === false) {
            echo "<script>alert('خطأ في معالجة الصورة، قد يكون نوع الملف غير مدعوم.');window.history.back();</script>";
            exit;
        }

        $pdo->prepare("UPDATE products SET name=?,description=?,supplier=?,sizes=?,category_id=?,price=?,discount_price=?,quantity=?,admin_note=?,image=? WHERE id=?")->execute([$name,$desc,$supplier,$sizes,$cat,$price,$disc,$qty,$note,$newImgName,$id]);

    } else {
        $pdo->prepare("UPDATE products SET name=?,description=?,supplier=?,sizes=?,category_id=?,price=?,discount_price=?,quantity=?,admin_note=? WHERE id=?")->execute([$name,$desc,$supplier,$sizes,$cat,$price,$disc,$qty,$note,$id]);
    }
    header("Location: admin.php?tab=tab-products&t=".time()); exit;
}

if (isset($_POST['add_admin'])) { $pdo->prepare("INSERT INTO admins (email,password,must_change_password) VALUES (?,?,1)")->execute([$_POST['new_admin_email'],password_hash($_POST['new_admin_pass'], PASSWORD_BCRYPT)]); header("Location: admin.php?tab=tab-admins&t=".time()); exit; }

// --- حذف مشرف (يعتمد على الصلاحية في قاعدة البيانات) ---
if (isset($_POST['delete_admin']) && $isSuperAdmin) {
    $delId = $_POST['admin_id_del'];
    // لا يمكن للمشرف حذف نفسه
    if ($delId != $_SESSION['admin_id']) {
        $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$delId]);
    }
    header("Location: admin.php?tab=tab-admins&t=".time()); exit;
}

// === 4. جلب البيانات ===
$categories = $pdo->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC);
$adminList = $pdo->query("SELECT * FROM admins")->fetchAll(PDO::FETCH_ASSOC);

$searchQuery = $_GET['search'] ?? '';
$searchProds = []; $searchCats = []; $searchOrds = [];

if ($searchQuery) {
    $term = "%$searchQuery%";
    $pS = $pdo->prepare("SELECT p.*, c.name as cat_name, (SELECT COALESCE(SUM(qty), 0) FROM cart WHERE product_id = p.id) as total_reserved FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.name LIKE ? OR p.admin_note LIKE ? OR p.supplier LIKE ?");
    $pS->execute([$term, $term, $term]); $searchProds = $pS->fetchAll(PDO::FETCH_ASSOC);
    $cS = $pdo->prepare("SELECT * FROM categories WHERE name LIKE ?"); $cS->execute([$term]); $searchCats = $cS->fetchAll(PDO::FETCH_ASSOC);
    $oS = $pdo->prepare("SELECT o.*, GROUP_CONCAT(CONCAT(oi.product_name, IF(oi.size IS NOT NULL AND oi.size != '', CONCAT(' <span style=\'color:#d00000; font-weight:bold;\'>[', oi.size, ']</span>'), ''), ' <span style=\'color:#666; font-size:0.85em;\'>(x', oi.qty, ')</span>') SEPARATOR '<br>') as items_summary FROM orders o LEFT JOIN order_items oi ON o.id = oi.order_id WHERE o.customer_name LIKE ? OR o.invoice_code LIKE ? GROUP BY o.id ORDER BY o.created_at DESC");
    $oS->execute([$term, $term]); $searchOrds = $oS->fetchAll(PDO::FETCH_ASSOC);
} else {
    // --- Products Pagination ---
    $prodPage = isset($_GET['prod_page']) ? (int)$_GET['prod_page'] : 1;
    $prodsPerPage = 10;
    $prodOffset = ($prodPage - 1) * $prodsPerPage;
    $totalProds = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $totalProdPages = ceil($totalProds / $prodsPerPage);
    $products = $pdo->query("SELECT p.*, c.name as cat_name, (SELECT COALESCE(SUM(qty), 0) FROM cart WHERE product_id = p.id) as total_reserved FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.created_at DESC LIMIT $prodsPerPage OFFSET $prodOffset")->fetchAll(PDO::FETCH_ASSOC);

    // --- Orders Pagination ---
    $ordPage = isset($_GET['ord_page']) ? (int)$_GET['ord_page'] : 1;
    $ordsPerPage = 10;
    $ordOffset = ($ordPage - 1) * $ordsPerPage;
    $totalOrds = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $totalOrdPages = ceil($totalOrds / $ordsPerPage);
    $ordQ = "SELECT o.*, GROUP_CONCAT(CONCAT(oi.product_name, IF(oi.size IS NOT NULL AND oi.size != '', CONCAT(' <span style=\'color:#d00000; font-weight:bold;\'>[', oi.size, ']</span>'), ''), ' <span style=\'color:#666; font-size:0.85em;\'>(x', oi.qty, ')</span>') SEPARATOR '<br>') as items_summary FROM orders o LEFT JOIN order_items oi ON o.id = oi.order_id GROUP BY o.id ORDER BY o.created_at DESC LIMIT $ordsPerPage OFFSET $ordOffset";
    $orders = $pdo->query($ordQ)->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم</title>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700&display=swap" rel="stylesheet">
    <link rel="manifest" href="admin-manifest.json">
    <link rel="stylesheet" href="style.min.css?v=<?php echo time(); ?>">
</head>
<body class="admin-body">

<div class="admin-container">
    <div class="admin-header">
        <h2 class="admin-h2">لوحة التحكم ⚙️</h2>
        <a href="logout.php" class="btn btn-red">خروج</a>
    </div>

    <form method="GET" action="admin.php">
        <input type="text" name="search" class="search-bar" placeholder="🔍 ابحث عن منتج، قسم، عميل..." value="<?= htmlspecialchars($searchQuery) ?>">
        <?php if($searchQuery): ?>
            <a href="admin.php" class="btn btn-blue" style="margin-bottom:20px; display:inline-block;">إلغاء البحث</a>
        <?php endif; ?>
    </form>

    <?php if ($searchQuery): ?>
        <!-- نتائج البحث -->
        <?php if (count($searchProds) > 0): ?>
            <div class="panel"><h3 class="admin-h3">نتائج المنتجات</h3><table class="admin-table"><thead><tr><th>صورة</th><th>المنتج</th><th>السعر</th><th>القسم</th><th>تحكم</th></tr></thead><tbody><?php foreach($searchProds as $prod): ?><tr><td data-label="صورة"><img src="uploads/<?= htmlspecialchars($prod['image']) ?>" class="thumb"></td><td data-label="المنتج"><b><?= htmlspecialchars($prod['name']) ?></b></td><td data-label="السعر"><?= htmlspecialchars($prod['price']) ?></td><td data-label="القسم"><?= htmlspecialchars($prod['cat_name']) ?></td><td data-label="تحكم"><button class="btn btn-orange" onclick='openEditProd(<?= json_encode($prod) ?>)'>تعديل</button><form method="POST" class="admin-form-inline" onsubmit="return confirm('حذف؟');"><input type="hidden" name="prod_id" value="<?= $prod['id'] ?>"><button name="delete_product" class="btn btn-red">حذف</button></form></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        <?php if (count($searchCats) > 0): ?>
            <div class="panel"><h3 class="admin-h3">نتائج الأقسام</h3><table class="admin-table"><thead><tr><th>ID</th><th>الاسم</th><th>تحكم</th></tr></thead><tbody><?php foreach($searchCats as $cat): ?><tr><td data-label="ID"><?= htmlspecialchars($cat['id']) ?></td><td data-label="الاسم"><?= htmlspecialchars($cat['name']) ?></td><td data-label="تحكم"><button class="btn btn-orange" onclick='openEditCat(<?= json_encode($cat) ?>)'>تعديل</button><form method="POST" class="admin-form-inline" onsubmit="return confirm('حذف؟');"><input type="hidden" name="cat_id" value="<?= $cat['id'] ?>"><button name="delete_category" class="btn btn-red">حذف</button></form></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        <?php if (count($searchOrds) > 0): ?>
            <div class="panel"><h3 class="admin-h3">نتائج الطلبات</h3><table class="admin-table"><thead><tr><th>رقم</th><th>العميل</th><th>المنتجات</th><th>الإجمالي</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody><?php foreach($searchOrds as $ord): ?><tr><td data-label="رقم">#<?= htmlspecialchars($ord['id']) ?></td><td data-label="العميل"><?= htmlspecialchars($ord['customer_name']) ?></td><td data-label="المنتجات"><?= $ord['items_summary'] ?></td><td data-label="الإجمالي"><?= htmlspecialchars($ord['total_amount']) ?></td><td data-label="الحالة"><?= htmlspecialchars($ord['status']) ?></td><td data-label="التاريخ"><?= htmlspecialchars($ord['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        <?php if (count($searchProds)==0 && count($searchCats)==0 && count($searchOrds)==0): ?><div class="panel" style="text-align:center;"><h3 class="admin-h3">لا توجد نتائج</h3></div><?php endif; ?>

    <?php else: ?>
        <div class="tabs-nav">
            <button class="tab-btn active" id="btn-orders" onclick="openTab(event, 'tab-orders')">📦 الطلبات</button>
            <button class="tab-btn" id="btn-products" onclick="openTab(event, 'tab-products')">👕 المنتجات</button>
            <button class="tab-btn" id="btn-cats" onclick="openTab(event, 'tab-cats')">📂 الأقسام</button>
            <button class="tab-btn" id="btn-admins" onclick="openTab(event, 'tab-admins')">👤 المشرفين</button>
            <button class="tab-btn" id="btn-procurement" onclick="openTab(event, 'tab-procurement')">🛒 المشتريات</button>
            <button class="tab-btn" onclick="openTab(event, 'tab-reports')">📊 التقارير</button>
        </div>

        <div id="tab-orders" class="tab-content active">
            <div class="panel">
                <div class="panel-header"><h3 class="admin-h3">سجل الطلبات</h3><form method="POST" onsubmit="return confirm('تفريغ؟');"><button name="delete_all_orders" class="btn btn-red">🗑️ تفريغ</button></form></div>
                <?php if (count($orders) > 0): ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>رقم</th>
                                <th>العميل</th>
                                <th>المنتجات</th>
                                <th>الإجمالي</th>
                                <th>الحالة</th>
                                <th>التاريخ</th>
                                <th>تحكم</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $stArr=['new'=>['جديد','#333'],'processing'=>['تجهيز','#e67e22'],'shipping'=>['توصيل','#3498db'],'delivered'=>['تم','#27ae60'],'canceled'=>['ملغي','#c0392b']]; foreach($orders as $ord): $k=$ord['status']?:'new'; ?>
                            <tr>
                                <td data-label="رقم">#<?= htmlspecialchars($ord['id']) ?><br><small><?= htmlspecialchars($ord['invoice_code']) ?></small></td>
                                <td data-label="العميل"><b><?= htmlspecialchars($ord['customer_name']) ?></b><br><small><?= htmlspecialchars($ord['customer_phone']) ?></small><br><small><?= htmlspecialchars($ord['address']) ?></small><?php if($ord['notes']) echo "<br><small style='color:red'>📝" . htmlspecialchars($ord['notes']) . "</small>"; ?></td>
                                <td data-label="المنتجات"><?= $ord['items_summary'] ?></td>
                                <td data-label="الإجمالي" style="color:green;font-weight:bold;"><?= htmlspecialchars($ord['total_amount']) ?></td>
                                <td data-label="الحالة"><form method="POST"><input type="hidden" name="order_id" value="<?= $ord['id'] ?>"><select name="status" onchange="this.form.submit()" class="admin-select" style="background:<?= $stArr[$k][1] ?>;color:#fff;border:none;font-weight:bold;padding:5px;"><?php foreach($stArr as $x=>$y): ?><option value="<?= $x ?>" <?= $k==$x?'selected':'' ?> style="background:#fff;color:#000;"><?= $y[0] ?></option><?php endforeach; ?></select><input type="hidden" name="update_order_status" value="1"></form></td>
                                <td data-label="التاريخ"><?= date('Y-m-d', strtotime($ord['created_at'])) ?></td>
                                <td data-label="تحكم">
                                <a href="admin.php?tab=orders&manage_order=<?= $ord['id'] ?>" class="btn btn-dark">🔍 تفاصيل/إرجاع</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalOrdPages; $i++): ?>
                            <a href="?tab=tab-orders&ord_page=<?= $i ?>" class="<?= ($ordPage == $i) ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <p style="text-align:center; padding: 20px;">لا توجد أي طلبات حالياً.</p>
                <?php endif; ?>
            </div>
        </div>

        <div id="tab-products" class="tab-content">
            <div class="panel">
                <div class="panel-header"><h3 class="admin-h3">المنتجات</h3><button class="btn btn-green" onclick="openModal('addProdModal')">+ منتج</button></div>
                <?php if (count($products) > 0): ?>
                    <table class="admin-table">
                        <thead><tr><th>صورة</th><th>المنتج</th><th>السعر</th><th>المخزون</th><th>القسم</th><th>تحكم</th></tr></thead>
                        <tbody>
                            <?php foreach($products as $prod): $sTxt="∞"; $bg=""; if($prod['quantity']!==null){ $n=$prod['quantity']-$prod['total_reserved']; if($n<=0){$sTxt="🚫 نفذت";$bg="background:#fff0f0";} elseif($n<5){$sTxt="⚠️ $n";$bg="background:#fffbe6";} else{$sTxt="✅ $n";} } ?>
                            <tr style="<?= $bg ?>">
                                <td data-label="صورة"><img src="uploads/<?= htmlspecialchars($prod['image']) ?>" class="thumb"></td>
                                <td data-label="المنتج"><b><?= htmlspecialchars($prod['name']) ?></b><?php if($prod['admin_note']) echo "<br><small style='color:red'>📝" . htmlspecialchars($prod['admin_note']) . "</small>"; ?></td>
                                <td data-label="السعر"><?php if($prod['discount_price']): ?><s><?= htmlspecialchars($prod['price']) ?></s> <b style="color:#d00000"><?= htmlspecialchars($prod['discount_price']) ?></b><?php else: echo htmlspecialchars($prod['price']); endif; ?></td>
                                <td data-label="المخزون"><?= $sTxt ?></td>
                                <td data-label="القسم"><?= htmlspecialchars($prod['cat_name']) ?></td>
                                <td data-label="تحكم"><button class="btn btn-orange" onclick='openEditProd(<?= json_encode($prod) ?>)'>تعديل</button><form method="POST" class="admin-form-inline" onsubmit="return confirm('حذف؟');"><input type="hidden" name="prod_id" value="<?= $prod['id'] ?>"><button name="delete_product" class="btn btn-red">حذف</button></form></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalProdPages; $i++): ?>
                            <a href="?tab=tab-products&prod_page=<?= $i ?>" class="<?= ($prodPage == $i) ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <p style="text-align:center; padding: 20px;">لم يتم إضافة أي منتجات بعد.</p>
                <?php endif; ?>
            </div>
        </div>

        <div id="tab-cats" class="tab-content">
            <div class="panel"><button class="btn btn-green" onclick="openModal('addCatModal')">+ قسم</button><table class="admin-table"><thead><tr><th>ID</th><th>الاسم</th><th>تحكم</th></tr></thead><tbody><?php foreach($categories as $cat): ?><tr><td data-label="ID"><?= htmlspecialchars($cat['id']) ?></td><td data-label="الاسم"><?= htmlspecialchars($cat['name']) ?></td><td data-label="تحكم"><button class="btn btn-orange" onclick='openEditCat(<?= json_encode($cat) ?>)'>تعديل</button><form method="POST" class="admin-form-inline" onsubmit="return confirm('حذف؟');"><input type="hidden" name="cat_id" value="<?= $cat['id'] ?>"><button name="delete_category" class="btn btn-red">حذف</button></form></td></tr><?php endforeach; ?></tbody></table></div>
        </div>

        <div id="tab-admins" class="tab-content">
            <div class="panel"><h3 class="admin-h3">إضافة مشرف</h3><form method="POST" style="display:flex; gap:10px; margin-bottom:20px;"><input type="email" name="new_admin_email" placeholder="البريد" class="admin-input" required><input type="text" name="new_admin_pass" placeholder="كلمة المرور" class="admin-input" required><button name="add_admin" class="btn btn-blue">إضافة</button></form><h3 class="admin-h3">المشرفين</h3><table class="admin-table"><thead><tr><th>ID</th><th>البريد</th><th>الصلاحية</th><th>تحكم</th></tr></thead><tbody><?php foreach($adminList as $adm): ?><tr><td data-label="ID"><?= htmlspecialchars($adm['id']) ?></td><td data-label="البريد"><?= htmlspecialchars($adm['email']) ?></td><td data-label="الصلاحية"><?= $adm['is_super'] == 1 ? '<span style="color:green;font-weight:bold;">عام</span>' : 'عادي' ?></td><td data-label="تحكم"><?php if($isSuperAdmin && $adm['id'] != $_SESSION['admin_id']): ?><form method="POST" onsubmit="return confirm('حذف؟');"><input type="hidden" name="admin_id_del" value="<?= $adm['id'] ?>"><button name="delete_admin" class="btn btn-red">حذف</button></form><?php else: echo '--'; endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
        </div>
        <!-- التقرير -->
         <div id="tab-reports" class="tab-content">
    <div class="panel">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:20px;">
            <h3>تقرير المبيعات (المجهزة فقط)</h3>
            <form method="GET" style="display:flex; gap:5px;">
                <input type="hidden" name="tab" value="tab-reports">
                <input type="date" name="date_from" value="<?= $dateFrom ?>">
                <input type="date" name="date_to" value="<?= $dateTo ?>">
                <button class="btn btn-blue">عرض</button>
            </form>
        </div>

        <!-- الإحصائيات -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">صافي الدخل</div>
                <div class="stat-num"><?= number_format($reportData['total_revenue'] ?? 0) ?> ر.ي</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">عدد الطلبات</div>
                <div class="stat-num"><?= $reportData['total_orders'] ?? 0 ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">قطع مباعة</div>
                <div class="stat-num"><?= $reportData['total_items_sold'] ?? 0 ?></div>
            </div>
        </div>

        <!-- الأكثر طلباً -->
        <h4 style="border-bottom:1px solid #eee; padding-bottom:5px;">🔥 الأكثر مبيعاً</h4>
        <table>
            <thead><tr><th>المنتج</th><th>الكمية المباعة</th></tr></thead>
            <tbody>
                <?php if($topProducts): foreach($topProducts as $top): ?>
                <tr><td data-label="المنتج"><?= $top['product_name'] ?></td><td data-label="الكمية"><?= $top['sold_qty'] ?></td></tr>
                <?php endforeach; else: echo "<tr><td colspan='2'>لا توجد بيانات</td></tr>"; endif; ?>
            </tbody>
        </table>
    </div>
</div>
        <!-- التقرير -->
        <!-- 5. تبويب قائمة المشتريات (للتجار) -->
        <div id="tab-procurement" class="tab-content">
            <div class="panel">
                <div class="panel-header">
                    <h3 class="admin-h3">🛒 قائمة التجهيز (حسب المورد)</h3>
                    <button onclick="window.print()" class="btn btn-blue">طباعة القائمة</button>
                </div>
                <?php
                $procSql = "SELECT p.supplier, oi.product_name, oi.size, SUM(oi.qty) as total_qty_needed FROM order_items oi JOIN orders o ON oi.order_id = o.id JOIN products p ON oi.product_name = p.name WHERE o.status IN ('new', 'processing') GROUP BY p.supplier, oi.product_name, oi.size ORDER BY p.supplier ASC";
                $procStmt = $pdo->query($procSql);
                $procList = $procStmt->fetchAll(PDO::FETCH_GROUP);
                ?>
                <div class="admin-table-responsive">
                    <?php if(count($procList) > 0): ?>
                        <?php foreach($procList as $supplier => $items): ?>
                            <div class="procurement-supplier-block">
                                <div class="procurement-supplier-header">
                                    🏪 المورد: <?= htmlspecialchars($supplier ? $supplier : 'غير محدد') ?>
                                </div>
                                <table class="procurement-table">
                                    <thead>
                                        <tr>
                                            <th>المنتج</th>
                                            <th>المقاس</th>
                                            <th>العدد المطلوب</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($items as $item): ?>
                                        <tr>
                                            <td data-label="المنتج" class="product-name"><?= htmlspecialchars($item['product_name']) ?></td>
                                            <td data-label="المقاس" class="size"><?= htmlspecialchars($item['size'] ? $item['size'] : '-') ?></td>
                                            <td data-label="العدد المطلوب" class="quantity"><?= htmlspecialchars($item['total_qty_needed']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center; padding:20px;">لا توجد طلبات جديدة للتجهيز.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="addCatModal" class="modal"><div class="modal-content"><span class="close" onclick="closeModal('addCatModal')">&times;</span><h3 class="admin-h3">إضافة قسم</h3><form method="POST"><label class="admin-label">اسم القسم</label><input type="text" name="cat_name" class="admin-input" required><button name="add_category" class="btn btn-green" style="margin-top:10px; width:100%;">حفظ</button></form></div></div>
<div id="editCatModal" class="modal"><div class="modal-content"><span class="close" onclick="closeModal('editCatModal')">&times;</span><h3 class="admin-h3">تعديل قسم</h3><form method="POST"><input type="hidden" name="cat_id" id="edit_cat_id"><label class="admin-label">اسم القسم</label><input type="text" name="cat_name" id="edit_cat_name" class="admin-input" required><button name="update_category" class="btn btn-orange" style="margin-top:10px; width:100%;">تحديث</button></form></div></div>

<div id="addProdModal" class="modal">
    <div class="modal-content"><span class="close" onclick="closeModal('addProdModal')">&times;</span><h3 class="admin-h3">إضافة منتج</h3>
    <form method="POST" enctype="multipart/form-data" onsubmit="return validatePrice(this)">
        <label class="admin-label">القسم</label><select name="category" class="admin-select" required><?php foreach($categories as $c) echo "<option value='{$c['id']}'>".htmlspecialchars($c['name'])."</option>"; ?></select>
        <label class="admin-label">اسم المنتج</label><input type="text" name="name" class="admin-input" required placeholder="اسم المنتج">
        <label class="admin-label">الوصف</label><textarea name="description" class="admin-textarea" placeholder="وصف المنتج للزبون" style="height:60px;"></textarea>
        <label class="admin-label">المقاسات (اختياري)</label><input type="text" name="sizes" class="admin-input" placeholder="مثال: S,M,L">
        <label class="admin-label">صورة المنتج</label><input type="file" name="image" class="admin-input" required>
        <label class="admin-label">اسم المورد (للتجار)</label><input type="text" name="supplier" class="admin-input" placeholder="مثال: محلات الأمل بالجملة">
        <label class="admin-label">السعر الرسمي</label><input type="number" name="price" class="admin-input" required placeholder="السعر">
        <label class="admin-label">سعر بعد الخصم (اختياري)</label><input type="number" name="discount" class="admin-input" placeholder="اتركه فارغاً إذا لا يوجد خصم">
        <label class="admin-label">الكمية المتوفرة</label><input type="number" name="qty" class="admin-input" placeholder="اتركه فارغاً إذا الكمية مفتوحة">
        <label class="admin-label">ملاحظة للمشرف (سرية)</label><textarea name="note" class="admin-textarea" placeholder="لن تظهر للزبون"></textarea>
        <button name="add_product" class="btn btn-green" style="margin-top:10px; width:100%;">نشر المنتج</button>
    </form></div>
</div>

<div id="editProdModal" class="modal">
    <div class="modal-content"><span class="close" onclick="closeModal('editProdModal')">&times;</span><h3 class="admin-h3">تعديل منتج</h3>
    <form method="POST" enctype="multipart/form-data" onsubmit="return validatePrice(this)">
        <input type="hidden" name="prod_id" id="edit_prod_id">
        <label class="admin-label">القسم</label><select name="category" id="edit_prod_cat" class="admin-select" required><?php foreach($categories as $c) echo "<option value='{$c['id']}'>".htmlspecialchars($c['name'])."</option>"; ?></select>
        <label class="admin-label">اسم المنتج</label><input type="text" name="name" id="edit_prod_name" class="admin-input" required>
        <label class="admin-label">الوصف</label><textarea name="description" id="edit_prod_desc" class="admin-textarea" style="height:60px;"></textarea>
        <label class="admin-label">المقاسات</label><input type="text" name="sizes" id="edit_prod_sizes" class="admin-input">
        <label class="admin-label">تغيير الصورة</label><input type="file" name="image" class="admin-input">
        <label class="admin-label">اسم المورد (للتجار)</label><input type="text" name="supplier" id="edit_prod_supplier" class="admin-input">
        <label class="admin-label">السعر الرسمي</label><input type="number" name="price" id="edit_prod_price" class="admin-input" required>
        <label class="admin-label">سعر بعد الخصم</label><input type="number" name="discount" id="edit_prod_disc" class="admin-input">
        <label class="admin-label">الكمية</label><input type="number" name="qty" id="edit_prod_qty" class="admin-input">
        <label class="admin-label">ملاحظة للمشرف</label><textarea name="note" id="edit_prod_note" class="admin-textarea"></textarea>
        <button name="update_product" class="btn btn-orange" style="margin-top:10px; width:100%;">حفظ التعديلات</button>
    </form></div>
</div>
<?php if($manageOrder): ?>
<div id="manageOrderModal" class="modal" style="display:flex;">
    <div class="modal-content">
        <span class="close" onclick="window.location.href='admin.php?tab=orders'">&times;</span>
        <h3>إدارة الطلب #<?= $manageOrder['invoice_code'] ?></h3>
        <p><strong>العميل:</strong> <?= $manageOrder['customer_name'] ?></p>
        
        <table style="margin-top:15px;">
            <thead><tr><th>المنتج</th><th>السعر</th><th>الحالة</th><th>تحكم</th></tr></thead>
            <tbody>
                <?php foreach($manageOrderItems as $item): ?>
                <tr style="<?= $item['item_status']=='returned' ? 'background:#ffebee' : '' ?>">
                    <td data-label="المنتج"><?= $item['product_name'] ?> <small>(x<?= $item['qty'] ?>)</small></td>
                    <td data-label="السعر"><?= $item['price'] ?></td>
                    <td data-label="الحالة">
                        <?= $item['item_status']=='returned' ? '<span style="color:red">مرتجع</span>' : '<span style="color:green">صالح</span>' ?>
                    </td>
                    <td data-label="تحكم">
                        <?php if($item['item_status']=='valid'): ?>
                            <form method="POST" onsubmit="return confirm('إرجاع هذا المنتج للمخزون وخصم سعره؟');">
                                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                <input type="hidden" name="order_id" value="<?= $manageOrder['id'] ?>">
                                <button name="return_item" class="btn btn-red" style="font-size:0.8rem; padding:5px;">إرجاع</button>
                            </form>
                        <?php else: ?> - <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<script src="admin.js"></script>
</body>
</html>