<?php
/**
 * Tadarus API - واجهة برمجة التطبيق
 */
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getInput();

switch ($action) {
    // ==================== المصادقة ====================
    case 'login':
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(['error' => 'يرجى إدخال اسم المستخدم وكلمة المرور'], 400);
        }
        
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password'])) {
            jsonResponse(['error' => 'اسم المستخدم أو كلمة المرور غير صحيحة'], 401);
        }
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        
        unset($user['password']);
        jsonResponse(['success' => true, 'user' => $user]);
        break;

    case 'logout':
        session_destroy();
        jsonResponse(['success' => true]);
        break;

    case 'check_auth':
        if (isset($_SESSION['user_id'])) {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, name, username, role, avatar_color, points, streak_days FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            jsonResponse(['authenticated' => true, 'user' => $user]);
        }
        jsonResponse(['authenticated' => false]);
        break;



    case 'register_parent':
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(['error' => 'يرجى إدخال اسم المستخدم وكلمة المرور'], 400);
        }
        if (strlen($password) < 4) {
            jsonResponse(['error' => 'كلمة المرور يجب أن تكون 4 أحرف على الأقل'], 400);
        }

        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $actionName = 'register_parent';
        
        $stmt = $db->prepare("SELECT attempts, last_attempt FROM rate_limits WHERE ip_address = ? AND action = ?");
        $stmt->execute([$ip, $actionName]);
        $rateLimit = $stmt->fetch();
        
        if ($rateLimit) {
            $lastAttempt = strtotime($rateLimit['last_attempt']);
            $hoursPassed = (time() - $lastAttempt) / 3600;
            if ($hoursPassed < 24 && $rateLimit['attempts'] >= 5) {
                jsonResponse(['error' => 'تم تجاوز الحد المسموح به لإنشاء الحسابات، يرجى المحاولة لاحقاً'], 429);
            }
            if ($hoursPassed >= 24) {
                $db->prepare("UPDATE rate_limits SET attempts = 1, last_attempt = NOW() WHERE ip_address = ? AND action = ?")->execute([$ip, $actionName]);
            } else {
                $db->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = NOW() WHERE ip_address = ? AND action = ?")->execute([$ip, $actionName]);
            }
        } else {
            $db->prepare("INSERT INTO rate_limits (ip_address, action, attempts) VALUES (?, ?, 1)")->execute([$ip, $actionName]);
        }
        
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'اسم المستخدم مستخدم بالفعل'], 400);
        }
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, username, password, role, avatar_color) VALUES (?, ?, ?, 'admin', '#0d9488')");
        $stmt->execute(['ولي الأمر', $username, $hash]);
        $userId = $db->lastInsertId();
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['role'] = 'admin';
        $_SESSION['name'] = 'ولي الأمر';
        jsonResponse(['success' => true]);
        break;

    case 'change_password':
        requireAdmin();
        $oldPassword = $input['old_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        if (empty($oldPassword) || empty($newPassword)) jsonResponse(['error' => 'البيانات غير مكتملة'], 400);
        if (strlen($newPassword) < 4) jsonResponse(['error' => 'كلمة المرور قصيرة جداً'], 400);
        
        $db = getDB();
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($oldPassword, $user['password'])) {
            jsonResponse(['error' => 'كلمة المرور الحالية غير صحيحة'], 401);
        }
        
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);
        jsonResponse(['success' => true]);
        break;

    // ==================== إدارة الأبناء ====================
    case 'get_students':
        requireAdmin();
        $db = getDB();
        $stmt = $db->prepare("SELECT id, name, username, avatar_color, points, streak_days, last_activity FROM users WHERE role = 'student' AND parent_id = ? ORDER BY points DESC");
        $stmt->execute([$_SESSION['user_id']]);
        $students = $stmt->fetchAll();
        
        // إضافة عدد الواجبات لكل ابن
        foreach ($students as &$s) {
            $st = $db->prepare("SELECT 
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'done' THEN 1 END) as done,
                COUNT(CASE WHEN status = 'reviewed' THEN 1 END) as reviewed
                FROM assignments WHERE student_id = ?");
            $st->execute([$s['id']]);
            $s['stats'] = $st->fetch();
        }
        jsonResponse(['students' => $students]);
        break;

    case 'add_student':
        requireAdmin();
        $name = trim($input['name'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $avatarColor = $input['avatar_color'] ?? '#' . substr(md5(rand()), 0, 6);
        
        if (empty($name) || empty($username) || empty($password)) {
            jsonResponse(['error' => 'جميع الحقول مطلوبة'], 400);
        }
        
        if (strlen($password) < 4) {
            jsonResponse(['error' => 'كلمة المرور يجب أن تكون 4 أحرف على الأقل'], 400);
        }
        
        $db = getDB();
        
        // التحقق من عدم تكرار اسم المستخدم
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'اسم المستخدم مستخدم بالفعل'], 400);
        }
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, username, password, role, avatar_color, parent_id) VALUES (?, ?, ?, 'student', ?, ?)");
        $stmt->execute([$name, $username, $hash, $avatarColor, $_SESSION['user_id']]);
        
        logActivity($_SESSION['user_id'], 'إضافة ابن', "تمت إضافة الابن: $name");
        
        jsonResponse(['success' => true, 'id' => $db->lastInsertId()]);
        break;

    case 'delete_student':
        requireAdmin();
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) jsonResponse(['error' => 'معرف غير صالح'], 400);
        
        $db = getDB();
        
        // الحصول على اسم الابن قبل الحذف
        $stmtName = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'student' AND parent_id = ?");
        $stmtName->execute([$id, $_SESSION['user_id']]);
        $studentName = $stmtName->fetchColumn();
        
        $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'student' AND parent_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        
        if ($studentName) {
            logActivity($_SESSION['user_id'], 'حذف ابن', "تم حذف الابن: $studentName");
        }
        
        jsonResponse(['success' => true]);
        break;

    case 'edit_student':
        requireAdmin();
        $id = intval($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $avatarColor = $input['avatar_color'] ?? '';
        
        if ($id <= 0 || empty($name) || empty($username)) {
            jsonResponse(['error' => 'جميع الحقول مطلوبة'], 400);
        }
        
        $db = getDB();
        
        // التحقق من عدم تكرار اسم المستخدم
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'اسم المستخدم مستخدم بالفعل'], 400);
        }
        
        if (!empty($password)) {
            if (strlen($password) < 4) jsonResponse(['error' => 'كلمة المرور يجب أن تكون 4 أحرف على الأقل'], 400);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET name = ?, username = ?, password = ?, avatar_color = ? WHERE id = ? AND role = 'student' AND parent_id = ?");
            $stmt->execute([$name, $username, $hash, $avatarColor, $id, $_SESSION['user_id']]);
        } else {
            $stmt = $db->prepare("UPDATE users SET name = ?, username = ?, avatar_color = ? WHERE id = ? AND role = 'student' AND parent_id = ?");
            $stmt->execute([$name, $username, $avatarColor, $id, $_SESSION['user_id']]);
        }
        
        logActivity($_SESSION['user_id'], 'تعديل ابن', "تم تعديل بيانات الابن: $name");
        
        jsonResponse(['success' => true]);
        break;

    // ==================== إدارة الواجبات ====================
    case 'add_assignment':
        requireAdmin();
        $studentId = intval($input['student_id'] ?? 0);
        $surahName = trim($input['surah_name'] ?? '');
        $fromAyah = intval($input['from_ayah'] ?? 1);
        $toAyah = intval($input['to_ayah'] ?? 1);
        $type = $input['type'] ?? 'حفظ';
        $dueDate = $input['due_date'] ?? null;
        $notes = trim($input['notes'] ?? '');
        
        if ($studentId <= 0 || empty($surahName)) {
            jsonResponse(['error' => 'يرجى اختيار الابن والسورة'], 400);
        }
        
        $validTypes = ['حفظ', 'تلاوة', 'مراجعة'];
        if (!in_array($type, $validTypes)) {
            jsonResponse(['error' => 'نوع الواجب غير صالح'], 400);
        }
        
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO assignments (student_id, assigned_by, surah_name, from_ayah, to_ayah, type, due_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$studentId, $_SESSION['user_id'], $surahName, $fromAyah, $toAyah, $type, $dueDate ?: null, $notes ?: null]);
        
        // الحصول على اسم الابن
        $st = $db->prepare("SELECT name FROM users WHERE id = ?");
        $st->execute([$studentId]);
        $studentName = $st->fetch()['name'] ?? '';
        
        logActivity($_SESSION['user_id'], 'إسناد واجب', "تم إسناد $type لـ $studentName: سورة $surahName ($fromAyah - $toAyah)");
        
        jsonResponse(['success' => true, 'id' => $db->lastInsertId()]);
        break;

    case 'get_assignments':
        $userId = requireAuth();
        $db = getDB();
        
        $studentId = $input['student_id'] ?? $_GET['student_id'] ?? null;
        $status = $input['status'] ?? $_GET['status'] ?? null;
        
        $where = [];
        $params = [];
        
        if ($_SESSION['role'] === 'student') {
            $where[] = "a.student_id = ?";
            $params[] = $userId;
        } elseif ($studentId) {
            $where[] = "a.student_id = ?";
            $params[] = intval($studentId);
            $where[] = "u.parent_id = ?";
            $params[] = $_SESSION['user_id'];
        } else if ($_SESSION['role'] === 'admin') {
            $where[] = "u.parent_id = ?";
            $params[] = $_SESSION['user_id'];
        }
        
        if ($status) {
            $where[] = "a.status = ?";
            $params[] = $status;
        }
        
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $stmt = $db->prepare("
            SELECT a.*, u.name as student_name, u.avatar_color as student_color, ab.name as assigned_by_name
            FROM assignments a
            JOIN users u ON a.student_id = u.id
            JOIN users ab ON a.assigned_by = ab.id
            $whereClause
            ORDER BY 
                CASE a.status 
                    WHEN 'pending' THEN 1 
                    WHEN 'done' THEN 2 
                    WHEN 'reviewed' THEN 3 
                END,
                a.created_at DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        $assignments = $stmt->fetchAll();
        
        jsonResponse(['assignments' => $assignments]);
        break;

    case 'complete_assignment':
        $userId = requireAuth();
        $assignmentId = intval($input['id'] ?? 0);
        
        if ($assignmentId <= 0) jsonResponse(['error' => 'معرف غير صالح'], 400);
        
        $db = getDB();
        
        // التحقق من ملكية الواجب
        $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ? AND student_id = ? AND status = 'pending'");
        $stmt->execute([$assignmentId, $userId]);
        $assignment = $stmt->fetch();
        
        if (!$assignment) {
            jsonResponse(['error' => 'الواجب غير موجود أو تم إنجازه بالفعل'], 404);
        }
        
        $stmt = $db->prepare("UPDATE assignments SET status = 'done', completed_at = NOW() WHERE id = ?");
        $stmt->execute([$assignmentId]);
        
        logActivity($userId, 'إنجاز واجب', "تم إنجاز {$assignment['type']}: سورة {$assignment['surah_name']}");
        
        jsonResponse(['success' => true]);
        break;

    case 'review_assignment':
        requireAdmin();
        $assignmentId = intval($input['id'] ?? 0);
        $points = intval($input['points'] ?? 10);
        $reviewType = $input['review_type'] ?? 'reviewed'; // 'reviewed' = تمت المراجعة
        
        if ($assignmentId <= 0) jsonResponse(['error' => 'معرف غير صالح'], 400);
        
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch();
        
        if (!$assignment) {
            jsonResponse(['error' => 'الواجب غير موجود'], 404);
        }
        
        // تحديث حالة الواجب
        $stmt = $db->prepare("UPDATE assignments SET status = 'reviewed', points_awarded = ?, completed_at = COALESCE(completed_at, NOW()) WHERE id = ?");
        $stmt->execute([$points, $assignmentId]);
        
        // إضافة النقاط للابن
        $stmt = $db->prepare("UPDATE users SET points = points + ? WHERE id = ?");
        $stmt->execute([$points, $assignment['student_id']]);
        
        // تحديث الخط اليومي
        updateStreak($assignment['student_id']);
        
        // الحصول على اسم الابن
        $st = $db->prepare("SELECT name FROM users WHERE id = ?");
        $st->execute([$assignment['student_id']]);
        $studentName = $st->fetch()['name'] ?? '';
        
        logActivity($_SESSION['user_id'], 'تسميع/مراجعة', "تم تسميع $studentName - سورة {$assignment['surah_name']} (+$points نقطة)");
        
        jsonResponse(['success' => true, 'points' => $points]);
        break;

    case 'edit_assignment':
        requireAdmin();
        $assignmentId = intval($input['id'] ?? 0);
        $surahName = trim($input['surah_name'] ?? '');
        $fromAyah = intval($input['from_ayah'] ?? 1);
        $toAyah = intval($input['to_ayah'] ?? 1);
        $type = $input['type'] ?? 'حفظ';
        $dueDate = $input['due_date'] ?? null;
        $notes = trim($input['notes'] ?? '');
        
        if ($assignmentId <= 0 || empty($surahName)) {
            jsonResponse(['error' => 'بيانات غير مكتملة'], 400);
        }
        
        $validTypes = ['حفظ', 'تلاوة', 'مراجعة'];
        if (!in_array($type, $validTypes)) {
            jsonResponse(['error' => 'نوع الواجب غير صالح'], 400);
        }
        
        $db = getDB();
        
        // يمكن تعديل الواجب فقط إذا كان قيد الانتظار
        $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ? AND status = 'pending'");
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch();
        
        if (!$assignment) {
            jsonResponse(['error' => 'الواجب غير موجود أو تم إنجازه ولا يمكن تعديله'], 404);
        }
        
        $stmt = $db->prepare("UPDATE assignments SET surah_name = ?, from_ayah = ?, to_ayah = ?, type = ?, due_date = ?, notes = ? WHERE id = ?");
        $stmt->execute([$surahName, $fromAyah, $toAyah, $type, $dueDate ?: null, $notes ?: null, $assignmentId]);
        
        // الحصول على اسم الابن
        $st = $db->prepare("SELECT name FROM users WHERE id = ?");
        $st->execute([$assignment['student_id']]);
        $studentName = $st->fetch()['name'] ?? '';
        
        logActivity($_SESSION['user_id'], 'تعديل واجب', "تم تعديل واجب $studentName: سورة $surahName");
        
        jsonResponse(['success' => true]);
        break;

    case 'delete_assignment':
        requireAdmin();
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) jsonResponse(['error' => 'معرف غير صالح'], 400);
        
        $db = getDB();
        
        // الحصول على تفاصيل الواجب قبل الحذف
        $stmtInfo = $db->prepare("SELECT a.surah_name, u.name as student_name FROM assignments a JOIN users u ON a.student_id = u.id WHERE a.id = ?");
        $stmtInfo->execute([$id]);
        $info = $stmtInfo->fetch();
        
        $stmt = $db->prepare("DELETE FROM assignments WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($info) {
            logActivity($_SESSION['user_id'], 'حذف واجب', "تم حذف واجب سورة {$info['surah_name']} للابن {$info['student_name']}");
        }
        
        jsonResponse(['success' => true]);
        break;

    // ==================== لوحة الصدارة والإحصائيات ====================
    case 'get_leaderboard':
        $userId = requireAuth();
        $db = getDB();
        
        if ($_SESSION['role'] === 'admin') {
            $parentId = $userId;
        } else {
            $stmt = $db->prepare("SELECT parent_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $parentId = $stmt->fetchColumn();
        }

        $stmt = $db->prepare("
            SELECT u.id, u.name, u.avatar_color, u.points, u.streak_days,
                COUNT(CASE WHEN a.status = 'reviewed' THEN 1 END) as completed_count
            FROM users u
            LEFT JOIN assignments a ON u.id = a.student_id
            WHERE u.role = 'student' AND u.parent_id = ?
            GROUP BY u.id
            ORDER BY u.points DESC, completed_count DESC
        ");
        $stmt->execute([$parentId]);
        $leaderboard = $stmt->fetchAll();
        jsonResponse(['leaderboard' => $leaderboard]);
        break;

    case 'get_stats':
        $userId = requireAuth();
        $db = getDB();
        
        if ($_SESSION['role'] === 'admin') {
            // إحصائيات ولي الأمر
            $stats = [];
            
            $stmt = $db->prepare("SELECT COUNT(*) as c FROM users WHERE role = 'student' AND parent_id = ?");
            $stmt->execute([$userId]);
            $stats['total_students'] = $stmt->fetch()['c'];
            
            $stmt = $db->prepare("SELECT COUNT(*) as c FROM assignments a JOIN users u ON a.student_id = u.id WHERE a.status = 'pending' AND u.parent_id = ?");
            $stmt->execute([$userId]);
            $stats['pending_assignments'] = $stmt->fetch()['c'];
            
            $stmt = $db->prepare("SELECT COUNT(*) as c FROM assignments a JOIN users u ON a.student_id = u.id WHERE a.status = 'done' AND u.parent_id = ?");
            $stmt->execute([$userId]);
            $stats['waiting_review'] = $stmt->fetch()['c'];
            
            $stmt = $db->prepare("SELECT COUNT(*) as c FROM assignments a JOIN users u ON a.student_id = u.id WHERE a.status = 'reviewed' AND DATE(a.completed_at) = CURDATE() AND u.parent_id = ?");
            $stmt->execute([$userId]);
            $stats['today_reviewed'] = $stmt->fetch()['c'];
            
            $stmt = $db->prepare("SELECT SUM(points) as p FROM users WHERE role = 'student' AND parent_id = ?");
            $stmt->execute([$userId]);
            $stats['total_points'] = $stmt->fetch()['p'] ?? 0;
            
            // آخر الأنشطة
            $stmt = $db->prepare("
                SELECT al.*, u.name as user_name 
                FROM activity_log al 
                JOIN users u ON al.user_id = u.id 
                WHERE u.parent_id = ? OR al.user_id = ?
                ORDER BY al.created_at DESC 
                LIMIT 10
            ");
            $stmt->execute([$userId, $userId]);
            $stats['recent_activity'] = $stmt->fetchAll();
            
            jsonResponse(['stats' => $stats]);
        } else {
            // إحصائيات الابن
            $stats = [];
            
            $stmt = $db->prepare("SELECT points, streak_days FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $stats['points'] = $user['points'];
            $stats['streak_days'] = $user['streak_days'];
            
            $stmt = $db->prepare("SELECT COUNT(*) as c FROM assignments WHERE student_id = ? AND status = 'pending'");
            $stmt->execute([$userId]);
            $stats['pending'] = $stmt->fetch()['c'];
            
            $stmt = $db->prepare("SELECT COUNT(*) as c FROM assignments WHERE student_id = ? AND status = 'reviewed'");
            $stmt->execute([$userId]);
            $stats['completed'] = $stmt->fetch()['c'];
            
            $stmt = $db->prepare("SELECT COUNT(*) as c FROM assignments WHERE student_id = ? AND status = 'done'");
            $stmt->execute([$userId]);
            $stats['waiting_review'] = $stmt->fetch()['c'];
            
            jsonResponse(['stats' => $stats]);
        }
        break;

    // ==================== المزامنة ====================
    case 'sync_data':
        $userId = requireAuth();
        $db = getDB();
        
        $lastSync = $input['last_sync'] ?? $_GET['last_sync'] ?? '2000-01-01 00:00:00';
        
        if ($_SESSION['role'] === 'admin') {
            $parentId = $userId;
        } else {
            $stmt = $db->prepare("SELECT parent_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $parentId = $stmt->fetchColumn();
        }

        // جلب التحديثات منذ آخر مزامنة
        $stmt = $db->prepare("
            SELECT a.*, u.name as student_name
            FROM assignments a
            JOIN users u ON a.student_id = u.id
            WHERE u.parent_id = ? AND (a.created_at > ? OR a.completed_at > ?)
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$parentId, $lastSync, $lastSync]);
        $assignments = $stmt->fetchAll();
        
        $stmt = $db->prepare("SELECT id, name, points, streak_days FROM users WHERE role = 'student' AND parent_id = ?");
        $stmt->execute([$parentId]);
        $students = $stmt->fetchAll();
        
        jsonResponse([
            'assignments' => $assignments,
            'students' => $students,
            'server_time' => date('Y-m-d H:i:s')
        ]);
        break;

    default:
        jsonResponse(['error' => 'إجراء غير معروف: ' . $action], 400);
}

// ==================== دوال مساعدة ====================

function logActivity($userId, $action, $details = '') {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $action, $details]);
}

function updateStreak($studentId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT last_activity FROM users WHERE id = ?");
    $stmt->execute([$studentId]);
    $user = $stmt->fetch();
    
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    if ($user['last_activity'] === $yesterday) {
        $db->prepare("UPDATE users SET streak_days = streak_days + 1, last_activity = ? WHERE id = ?")
           ->execute([$today, $studentId]);
    } elseif ($user['last_activity'] !== $today) {
        $db->prepare("UPDATE users SET streak_days = 1, last_activity = ? WHERE id = ?")
           ->execute([$today, $studentId]);
    }
}
