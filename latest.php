<?php
include 'include/db.php';
// جلب المفضلات
$favMap = [];
if(isset($user_session)) {
    $fStmt = $pdo->prepare("SELECT product_id FROM favorites WHERE session_id = ?");
    $fStmt->execute([$user_session]);
    while ($fRow = $fStmt->fetch(PDO::FETCH_ASSOC)) { $favMap[$fRow['product_id']] = true; }
}
?>
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أحدث المنتجات</title>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.min.css">
    <link rel="icon" type="image/x-icon" href="icons/icon-192x192.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>
<body>

    <header>
        <a href="index.php" class="logo-container" style="text-decoration:none;">
            <span class="logo-text-sam">SAM</span>
            <span class="logo-text-store">STORE</span>
        </a>
        <h2 class="category-title">أحدث المنتجات</h2>
        <a href="index.php" class="btn-back">عودة للرئيسية ⌂</a>
    </header>

    <div class="container">
        <div class="products-grid">
            <?php
            $sql = "SELECT p.*, (SELECT COALESCE(SUM(qty), 0) FROM cart WHERE product_id = p.id) as total_reserved
                    FROM products p WHERE (quantity > 0 OR quantity IS NULL) ORDER BY created_at DESC";
            $stmt = $pdo->query($sql);

            while($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                if ($row['quantity'] !== null) {
                    $realQty = $row['quantity'] - $row['total_reserved'];
                    if ($realQty <= 0) continue;
                } else { $realQty = null; }
                $price = $row['discount_price'] ? $row['discount_price'] : $row['price'];
            ?>
            <div class="product-card">
                <button class="fav-btn <?= isset($favMap[$row['id']]) ? 'active' : '' ?>" onclick="toggleFav(this, <?= $row['id'] ?>)">
                    <i class="<?= isset($favMap[$row['id']]) ? 'fa-solid fa-heart' : 'fa-regular fa-heart' ?>"></i>
                </button>
                <img src="uploads/<?= htmlspecialchars($row['image']) ?>" alt="<?= htmlspecialchars($row['name']) ?>">
                <h4><?= htmlspecialchars($row['name']) ?></h4>
                <p class="product-desc"><?= htmlspecialchars($row['description'] ?? '') ?></p>
                <div class="price-box">
                    <?= $row['discount_price'] ? "<span class='old-price'>".htmlspecialchars($row['price'])."</span>" : "" ?>
                    <span class="new-price"><?= htmlspecialchars($price) ?> ر.ي</span>
                </div>
                <?php if ($realQty !== null): ?>
                    <div class="qty-label">متبقي: <span id="qty_lat_<?= $row['id'] ?>"><?= htmlspecialchars($realQty) ?></span></div>
                <?php else: ?> <div class="spacer-20"></div> <?php endif; ?>
                <button class="btn-add" onclick="checkSizeAndAdd(<?= $row['id'] ?>, <?= $realQty !== null ? 'true' : 'false' ?>, 'qty_lat_<?= $row['id'] ?>', '<?= htmlspecialchars($row['sizes'] ?? '') ?>')">أضف للسلة</button>
            </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- ================== النوافذ والقوائم (مشتركة) ================== -->

    <!-- قائمة الأقسام -->
    <div id="catsMenu" class="cats-menu-container">
        <h4>تصفح الأقسام</h4>
        <a href="latest.php">✨ أحدث المنتجات</a>
        <?php
        $menuCats = $pdo->query("SELECT * FROM categories");
        while($c = $menuCats->fetch(PDO::FETCH_ASSOC)):
            $checkSql = "SELECT COUNT(*) FROM products WHERE category_id = ? AND (quantity > 0 OR quantity IS NULL)";
            $stmt = $pdo->prepare($checkSql); $stmt->execute([$c['id']]);
            if ($stmt->fetchColumn() > 0): ?>
            <a href="category.php?id=<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></a>
        <?php endif; endwhile; ?>
    </div>

    <!-- نافذة السلة -->
    <div id="cartModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="document.getElementById('cartModal').style.display='none'">&times;</span>
            <h2 class="modal-title">سلة المشتريات</h2>
            <div id="cartItems"></div>
            <div class="modal-footer">
                <input type="text" id="custName" class="form-input" placeholder="اسم المستلم (الأول والثاني) *" required>
                <input type="number" id="custPhone" class="form-input" placeholder="رقم الهاتف (اختياري)">
                <div class="form-group-relative">
                    <input type="text" id="custAddress" class="form-input input-with-icon"
                           placeholder="العنوان بالتفصيل (أو اضغط الأيقونة 🎯)"
                           required>
                    <button type="button" onclick="getLocation()" title="تحديد موقعي الحالي" class="location-btn">
                        <i class="fa-solid fa-location-crosshairs"></i>
                    </button>
                </div>
                <small class="form-hint">
                    اضغط الأيقونة لتعبئة الحقل برابط الخريطة تلقائياً 🌍
                </small>
                <textarea id="custNotes" class="form-input notes-textarea" placeholder="ملاحظات (اختياري)"></textarea>
                <button id="btnCheckout" class="btn-add btn-checkout" onclick="checkout()">شراء وإصدار فاتورة</button>
            </div>
        </div>
    </div>

    <!-- نافذة المقاسات -->
    <div id="sizeModal" class="modal">
        <div class="modal-content size-modal-content">
            <span class="close-modal" onclick="document.getElementById('sizeModal').style.display='none'">&times;</span>
            <h3>اختر المقاس المطلوب</h3>
            <div id="sizesContainer" class="sizes-container"></div>
        </div>
    </div>

    <!-- نافذة المعاينة -->
    <div id="previewModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="document.getElementById('previewModal').style.display='none'">&times;</span>
            <div id="previewContent"></div>
        </div>
    </div>

    <!-- الفاتورة -->
    <div id="invoice-area" style="display:none; background:#fff; padding:20px; border:1px solid #333; width:500px; font-family:'Almarai', sans-serif;">
        <div style="text-align:center; border-bottom:2px solid #eee; padding-bottom:10px; margin-bottom:10px;">
            <a href="index.php" class="logo-container">
            <span class="logo-text-store">STORE</span>
            <span class="logo-text-sam">SAM</span>
        </a>
            <p style="margin:0; font-weight:bold; color:#d00000;">رقم الفاتورة: #<span id="invId"></span></p>
        </div>
        <div style="margin-bottom:15px; font-size:0.9rem;">
            <p style="margin:5px 0;"><strong>العميل:</strong> <span id="invName"></span></p>
            <p style="margin:5px 0;"><strong>الهاتف:</strong> <span id="invPhone"></span></p>
            <p style="margin:5px 0;"><strong>العنوان:</strong> <span id="invAddress"></span></p>
            <p style="margin:5px 0;"><strong>التاريخ:</strong> <?= date('Y-m-d h:i A') ?></p>
        </div>
        <table border="1" width="100%" style="border-collapse:collapse; text-align:center; border-color:#eee;">
            <thead style="background:#f9f9f9;"><tr><th style="padding:8px;">المنتج</th><th>السعر</th><th>الكمية</th></tr></thead>
            <tbody id="invBody"></tbody>
        </table>
        <div style="text-align:left; margin-top:15px;"><h3>الإجمالي: <span id="invTotal" style="color:#d00000;"></span> ر.ي</h3></div>
        <p>- <span style="color: red; font-weight: bold;">#ملاحظة :</span> يرجى ارسال الفاتورة الى مالك المتجر ليتم تجهيز طلبك، شكرا لتعاملكم معنا.</p>
    </div>

    <!-- الشريط السفلي -->
    <nav class="bottom-nav">
        <a href="https://wa.me/967738183179" class="nav-item" target="_blank"><i class="fa-brands fa-whatsapp" style="font-size:1.4rem;"></i><span>تواصل</span></a>
        <a href="track.php" class="nav-item"><i class="fa-solid fa-truck-fast"></i><span>تتبع الطلب</span></a>
        <div class="nav-item center-fab-container">
            <div class="center-fab" onclick="openCart()"><i class="fa-solid fa-cart-shopping"></i><span class="nav-cart-count" id="cartCount"><?= getCartCount($pdo, $user_session) ?></span></div>
        </div>
        <a href="favorites.php" class="nav-item"><i class="fa-regular fa-heart"></i><span>المفضلة</span></a>
        <div class="nav-item" onclick="toggleCatMenu()"><i class="fa-solid fa-bars"></i><span>الأقسام</span></div>
    </nav>

    <script src="script.js"></script>
</body>
</html>