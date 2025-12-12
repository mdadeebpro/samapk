<?php
include 'include/db.php';
// جلب المفضلات مع تفاصيل المنتج والمخزون
$sql = "SELECT p.*, f.id as fav_id,
        (SELECT COALESCE(SUM(qty), 0) FROM cart WHERE product_id = p.id) as total_reserved
        FROM favorites f
        JOIN products p ON f.product_id = p.id
        WHERE f.session_id = ?
        ORDER BY f.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$user_session]);
?>
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مفضلاتي - متجر سام</title>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.min.css">
</head>
<body>
    <header>
        <a href="index.php" class="logo-container">
            <span class="logo-text-store">STORE</span>
            <span class="logo-text-sam">SAM</span>
        </a>
        <h1>❤️ منتجاتي المفضلة</h1>
        <a href="index.php" class="btn-back">عودة للرئيسية</a>
    </header>

    <div class="container">
        <div class="products-grid">
            <?php
            $hasItems = false;
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                $hasItems = true;
                if ($row['quantity'] !== null) {
                    $realQty = $row['quantity'] - $row['total_reserved'];
                    // هنا نعرض المنتج حتى لو نفذت الكمية، ليراه الزبون ويحذفه أو ينتظره
                } else { $realQty = null; }
                $price = $row['discount_price'] ? $row['discount_price'] : $row['price'];
            ?>
            <div class="product-card" id="fav_card_<?= $row['id'] ?>">
                <!-- زر حذف من المفضلة -->
                <button class="fav-btn active" onclick="removeFromFavPage(<?= $row['id'] ?>)">❤️</button>

                <img src="uploads/<?= htmlspecialchars($row['image']) ?>">
                <h4><?= htmlspecialchars($row['name']) ?></h4>
                <div class="price-box">
                    <?php if (!empty($row['discount_price']) && $row['discount_price'] > 0): ?>
                        <span class="old-price"><?= htmlspecialchars($row['price']) ?></span>
                        <span class="new-price"><?= htmlspecialchars($row['discount_price']) ?> ر.ي</span>
                    <?php else: ?>
                        <span class="new-price"><?= htmlspecialchars($row['price']) ?> ر.ي</span>
                    <?php endif; ?>
                </div>
                <?php if ($realQty !== null): ?>
                    <?php if($realQty > 0): ?>


                    <?php else: ?>
                        <p class="out-of-stock-message">نفذت الكمية</p>
                        <button class="btn-add" disabled>غير متوفر</button>
                    <?php endif; ?>


                <?php endif; ?>

            </div>
            <?php endwhile; ?>

            <?php if(!$hasItems): ?>
                <p class="empty-message">لم تضف أي منتج للمفضلة بعد.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- هنا تحتاج لنسخ دوال الجافاسكريبت (addToCart, checkSizeAndAdd, etc) من index.php -->
    <!-- ونسخ كود نوافذ السلة والمقاسات لكي يعمل زر الإضافة -->
    <script>
        // دالة خاصة لصفحة المفضلة لإخفاء البطاقة عند الحذف
        function removeFromFavPage(pid) {
            let fd = new FormData();
            fd.append('action', 'toggle_favorite');
            fd.append('product_id', pid);
            fetch('api.php', { method: 'POST', body: fd })
            .then(r=>r.json()).then(d=>{
                // إخفاء العنصر فوراً
                document.getElementById('fav_card_'+pid).style.display = 'none';
            });
        }

        // ... (ضع هنا دوال الإضافة للسلة كما في الصفحات الأخرى) ...
    </script>
    <script src="script.js"></script>
</body>
</html>