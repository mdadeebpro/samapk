<?php
// db.php
$host = 'localhost';
$dbname = 'sam';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage());
}

session_start();

// التحقق من الجلسة أو إنشاؤها
if (isset($_COOKIE['user_session_id'])) {
    $sessionId = $_COOKIE['user_session_id'];
} else {
    $sessionId = bin2hex(random_bytes(16));
    setcookie('user_session_id', $sessionId, time() + (86400 * 30), "/");
}

// === الإصلاح: تأكد دائماً أن المستخدم موجود في قاعدة البيانات ===
// نستخدم INSERT IGNORE لنتجاهل الخطأ إذا كان موجوداً مسبقاً، وننشئه إذا لم يكن موجوداً (بسبب الحذف)
try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (session_id) VALUES (?)");
    $stmt->execute([$sessionId]);
} catch (Exception $e) {
    // تجاهل الخطأ في حال وجود مشاكل أخرى
}

$user_session = $sessionId;

// دالة لجلب عدد عناصر السلة
function getCartCount($pdo, $session) {
    $stmt = $pdo->prepare("SELECT SUM(qty) as total FROM cart WHERE session_id = ?");
    $stmt->execute([$session]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res['total'] ? $res['total'] : 0;
}
?>