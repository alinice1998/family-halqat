<?php
/**
 * Tadarus - إعدادات قاعدة البيانات والجلسة (مثال)
 * 
 * تنبيه: قم بنسخ هذا الملف وإعادة تسميته إلى config.php 
 * ووضع بيانات الاتصال الفعلية بقاعدة البيانات الخاصة بك.
 */

// إعدادات قاعدة البيانات
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name'); // ضع اسم قاعدة البيانات هنا
define('DB_USER', 'your_username');      // ضع اسم المستخدم هنا
define('DB_PASS', 'your_password');      // ضع كلمة المرور هنا
define('DB_CHARSET', 'utf8mb4');

// بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// الاتصال بقاعدة البيانات
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            jsonResponse(['error' => 'فشل الاتصال بقاعدة البيانات'], 500);
            exit;
        }
    }
    return $pdo;
}

// إرسال استجابة JSON
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من تسجيل الدخول
function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['error' => 'يرجى تسجيل الدخول'], 401);
    }
    return $_SESSION['user_id'];
}

// التحقق من صلاحية المدير
function requireAdmin() {
    requireAuth();
    if ($_SESSION['role'] !== 'admin') {
        jsonResponse(['error' => 'ليس لديك الصلاحية'], 403);
    }
}

// الحصول على بيانات الإدخال
function getInput() {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if (!$data) {
        $data = $_POST;
    }
    return $data;
}
