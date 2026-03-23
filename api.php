<?php
session_start();
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');
require_once 'config.php';
require_once 'db.php';

$action = $_REQUEST['action'] ?? '';

function respond($d) { echo json_encode($d); exit; }
function error($msg, $code = 400) { http_response_code($code); respond(['success' => false, 'error' => $msg]); }
function success($d = []) { respond(array_merge(['success' => true], $d)); }
function requireLogin() { if (!isset($_SESSION['user_id'])) error('Not logged in.', 401); }
function requireAdmin() {
    requireLogin();
    if ($_SESSION['active_role'] !== 'admin') error('Admin access required.', 403);
}
function logAction($db, $action, $detail) {
    $email = $_SESSION['user_email'] ?? 'system';
    $stmt  = $db->prepare("INSERT INTO activity_log (action, detail, user_email) VALUES (?,?,?)");
    $stmt->bind_param("sss", $action, $detail, $email);
    $stmt->execute();
}

switch ($action) {
    case 'login':
        error('Please use Google Sign-In to login.');
        break;

    case 'logout':
        $db = getDB();
        if (isset($_SESSION['user_email']))
            logAction($db, 'Logout', "{$_SESSION['user_name']} ({$_SESSION['user_email']}) signed out");
        session_destroy();
        success();
        break;

    case 'check_session':
        if (!isset($_SESSION['user_id'])) {
            success(['logged_in' => false]);
        } else {
            success([
                'logged_in'   => true,
                'name'        => $_SESSION['user_name'],
                'email'       => $_SESSION['user_email'],
                'avatar'      => $_SESSION['user_avatar'] ?? '',
                'role'        => $_SESSION['user_role'],
                'active_role' => $_SESSION['active_role'],
            ]);
        }
        break;

    case 'switch_role':
        requireLogin();
        if ($_SESSION['user_role'] !== 'admin') error('Only admins can switch roles.');
        $body   = json_decode(file_get_contents('php://input'), true);
        $target = $body['role'] ?? '';
        if (!in_array($target, ['admin','user'])) error('Invalid role.');

        $_SESSION['active_role'] = $target;
        $db   = getDB();
        $stmt = $db->prepare("UPDATE allowed_users SET active_role=? WHERE id=?");
        $stmt->bind_param("si", $target, $_SESSION['user_id']);
        $stmt->execute();
        logAction($db, 'Role Switch', "{$_SESSION['user_name']} switched to '$target'");
        success(['active_role' => $target]);
        break;

    case 'get_users':
        requireAdmin();
        $db   = getDB();
        $rows = $db->query("SELECT id, email, name, role, is_active, created_at FROM allowed_users ORDER BY role DESC, name ASC")->fetch_all(MYSQLI_ASSOC);
        success(['users' => $rows]);
        break;

    case 'add_user':
        requireAdmin();
        $body     = json_decode(file_get_contents('php://input'), true);
        $email    = strtolower(trim($body['email']    ?? ''));
        $name     = trim($body['name']     ?? '');
        $password = trim($body['password'] ?? '');
        $role     = trim($body['role']     ?? 'user');

        if (!$email || !$name || !$password) error('Email, name, and password are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error('Invalid email address.');
        if (!in_array($role, ['admin','user'])) error('Invalid role.');

        $db   = getDB();
        $chk  = $db->prepare("SELECT id FROM allowed_users WHERE email=?");
        $chk->bind_param("s", $email); $chk->execute();
        if ($chk->get_result()->num_rows > 0) error('This email is already in the system.');

        $hashed = md5($password);
        $stmt   = $db->prepare("INSERT INTO allowed_users (email, password, name, role, active_role) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sssss", $email, $hashed, $name, $role, $role);
        if (!$stmt->execute()) error('Failed to add user.');

        logAction($db, 'Add User', "Added $role: $name ($email)");
        success(['message' => 'User added successfully.']);
        break;

    case 'edit_user':
        requireAdmin();
        $body  = json_decode(file_get_contents('php://input'), true);
        $id    = intval($body['id'] ?? 0);
        $name  = trim($body['name'] ?? '');
        $role  = trim($body['role'] ?? 'user');
        $active = intval($body['is_active'] ?? 1);
        $newPass = trim($body['password'] ?? '');

        if (!$id || !$name) error('ID and name required.');

        $db = getDB();
        if ($newPass) {
            $hashed = md5($newPass);
            $stmt   = $db->prepare("UPDATE allowed_users SET name=?, role=?, is_active=?, active_role=?, password=? WHERE id=?");
            $stmt->bind_param("ssissi", $name, $role, $active, $role, $hashed, $id);
        } else {
            $stmt = $db->prepare("UPDATE allowed_users SET name=?, role=?, is_active=?, active_role=? WHERE id=?");
            $stmt->bind_param("ssisi", $name, $role, $active, $role, $id);
        }
        if (!$stmt->execute()) error('Failed to update user.');
        logAction($db, 'Edit User', "Updated user ID $id - $name");
        success(['message' => 'User updated.']);
        break;

    case 'delete_user':
        requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true);
        $id   = intval($body['id'] ?? 0);
        if (!$id) error('ID required.');
        if ($id === $_SESSION['user_id']) error('You cannot delete your own account.');

        $db   = getDB();
        $info = $db->prepare("SELECT name, email FROM allowed_users WHERE id=?");
        $info->bind_param("i", $id); $info->execute();
        $row  = $info->get_result()->fetch_assoc();

        $stmt = $db->prepare("DELETE FROM allowed_users WHERE id=?");
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) error('Failed to delete user.');

        logAction($db, 'Delete User', "Deleted user: {$row['name']} ({$row['email']})");
        success(['message' => 'User removed.']);
        break;

    case 'block_user':
        requireAdmin();
        $body    = json_decode(file_get_contents('php://input'), true);
        $id      = intval($body['id'] ?? 0);
        $blocked = intval($body['blocked'] ?? 1);
        if (!$id) error('ID required.');
        if ($id === $_SESSION['user_id']) error('You cannot block yourself.');
        $db   = getDB();
        $stmt = $db->prepare("UPDATE allowed_users SET is_active=? WHERE id=?");
        $active = $blocked ? 0 : 1;
        $stmt->bind_param("ii", $active, $id);
        if (!$stmt->execute()) error('Failed to update.');
        $action = $blocked ? 'Block User' : 'Unblock User';
        logAction($db, $action, "User ID $id has been " . ($blocked ? 'blocked' : 'unblocked'));
        success(['message' => $blocked ? 'User blocked.' : 'User unblocked.']);
        break;
    
    case 'get_students':
        requireAdmin();
        $db     = getDB();
        $search = trim($_GET['search'] ?? '');
        if ($search) {
            $like = "%$search%";
            $stmt = $db->prepare("SELECT * FROM students WHERE id LIKE ? OR name LIKE ? OR college LIKE ? ORDER BY name");
            $stmt->bind_param("sss", $like, $like, $like);
        } else {
            $stmt = $db->prepare("SELECT * FROM students ORDER BY name");
        }
        $stmt->execute();
        success(['students' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        break;

    case 'get_student':
        $db   = getDB();
        $id   = strtoupper(trim($_GET['id']   ?? ''));
        $name = trim($_GET['name'] ?? '');
        if ($id) {
            $stmt = $db->prepare("SELECT * FROM students WHERE id=?");
            $stmt->bind_param("s", $id);
        } elseif ($name) {
            $stmt = $db->prepare("SELECT * FROM students WHERE name=?");
            $stmt->bind_param("s", $name);
        } else { error('Provide id or name.'); }
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        if (!$student) error('Student not found.');
        success(['student' => $student]);
        break;

    case 'add_student':
        requireAdmin();
        $body    = json_decode(file_get_contents('php://input'), true);
        $id      = strtoupper(trim($body['id']      ?? ''));
        $name    = trim($body['name']    ?? '');
        $age     = intval($body['age']   ?? 0);
        $college = trim($body['college'] ?? $body['department'] ?? '');
        $dept    = trim($body['department'] ?? $body['college'] ?? '');
        $emp     = trim($body['employee_type'] ?? 'Student');
        $email   = trim($body['email']   ?? '');

        if (!$id || !$name || !$age || !$college) error('ID, Name, Age, and College are required.');
        $db  = getDB();
        $chk = $db->prepare("SELECT id FROM students WHERE id=?");
        $chk->bind_param("s", $id); $chk->execute();
        if ($chk->get_result()->num_rows > 0) error('Student ID already exists.');

        $stmt = $db->prepare("INSERT INTO students (id,name,age,college,department,employee_type,email) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("ssissss", $id, $name, $age, $college, $dept, $emp, $email);
        if (!$stmt->execute()) error('Failed to add student.');
        logAction($db, 'Add Student', "Added $emp $id - $name ($college)");
        success();
        break;

    case 'register_student':
        $body    = json_decode(file_get_contents('php://input'), true);
        $id      = strtoupper(trim($body['id']      ?? ''));
        $name    = trim($body['name']    ?? '');
        $age     = intval($body['age']   ?? 0);
        $college = trim($body['college'] ?? $body['department'] ?? '');
        $dept    = trim($body['department'] ?? $body['college'] ?? '');
        $emp     = trim($body['employee_type'] ?? 'Student');
        $email   = trim($body['email']   ?? '');
        if (!$id || !$name || !$age || !$college) error('ID, Name, Age, and College are required.');
        if ($age < 1 || $age > 120) error('Please enter a valid age.');
        $db  = getDB();
        $chk = $db->prepare("SELECT id FROM students WHERE id=?");
        $chk->bind_param("s", $id); $chk->execute();
        if ($chk->get_result()->num_rows > 0) error('Student ID already exists.');
        $stmt = $db->prepare("INSERT INTO students (id,name,age,college,department,employee_type,email) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("ssissss", $id, $name, $age, $college, $dept, $emp, $email);
        if (!$stmt->execute()) error('Registration failed. Please try again.');
        $le = 'self-registration';
        $ls = $db->prepare("INSERT INTO activity_log (action, detail, user_email) VALUES (?,?,?)");
        $la = 'Student Self-Registration'; $ld = "New student: $emp $id - $name ($college)";
        $ls->bind_param("sss", $la, $ld, $le); $ls->execute();
        success(['message' => 'Registration successful!', 'student_id' => $id]);
        break;

    case 'block_student':
        requireAdmin();
        $body    = json_decode(file_get_contents('php://input'), true);
        $id      = strtoupper(trim($body['id'] ?? ''));
        $blocked = intval($body['blocked'] ?? 1);
        if (!$id) error('ID required.');
        $db = getDB();

        $db->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS blocked TINYINT(1) DEFAULT 0");

        $stmt = $db->prepare("UPDATE students SET blocked=? WHERE id=?");
        $stmt->bind_param("is", $blocked, $id);
        if (!$stmt->execute()) error('Failed to update student status.');
        $action = $blocked ? 'Block Student' : 'Unblock Student';
        logAction($db, $action, "$id has been " . ($blocked ? 'blocked' : 'unblocked'));
        success(['message' => $blocked ? 'Student blocked.' : 'Student unblocked.']);
        break;

    case 'edit_student':
        requireAdmin();
        $body    = json_decode(file_get_contents('php://input'), true);
        $id      = strtoupper(trim($body['id']      ?? ''));
        $name    = trim($body['name']    ?? '');
        $age     = intval($body['age']   ?? 0);
        $college = trim($body['college'] ?? $body['department'] ?? '');
        $dept    = trim($body['department'] ?? $body['college'] ?? '');
        $emp     = trim($body['employee_type'] ?? 'Student');
        $email   = trim($body['email']   ?? '');

        if (!$id || !$name || !$age || !$college) error('Required fields missing.');
        $db   = getDB();
        $stmt = $db->prepare("UPDATE students SET name=?,age=?,college=?,department=?,employee_type=?,email=? WHERE id=?");
        $stmt->bind_param("sisssss", $name, $age, $college, $dept, $emp, $email, $id);
        if (!$stmt->execute()) error('Failed to update.');
        logAction($db, 'Edit Student', "Updated $id - $name");
        success();
        break;

    case 'delete_student':
        requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true);
        $id   = strtoupper(trim($body['id'] ?? ''));
        if (!$id) error('ID required.');
        $db   = getDB();
        $info = $db->prepare("SELECT name FROM students WHERE id=?");
        $info->bind_param("s",$id); $info->execute();
        $row  = $info->get_result()->fetch_assoc();
        $stmt = $db->prepare("DELETE FROM students WHERE id=?");
        $stmt->bind_param("s", $id);
        if (!$stmt->execute()) error('Failed to delete.');
        logAction($db, 'Delete Student', "Deleted $id - ".($row['name']??''));
        success();
        break;

    case 'approve_entry':
        requireAdmin();
        $body        = json_decode(file_get_contents('php://input'), true);
        $sid         = strtoupper(trim($body['student_id']  ?? ''));
        $activities  = $body['activities']  ?? [];
        $visitReason = trim($body['visit_reason'] ?? 'Library Visit');
        if (!$sid || empty($activities)) error('Student ID and activities required.');
        $db  = getDB();
        $chk = $db->prepare("SELECT * FROM students WHERE id=?");
        $chk->bind_param("s",$sid); $chk->execute();
        $student = $chk->get_result()->fetch_assoc();
        if (!$student) error('Student not found.');

        if (!empty($student['blocked']) && $student['blocked'] == 1) {
            error('Access denied. This student has been blocked from library access.');
        }

        $active = $db->prepare("SELECT id FROM visits WHERE student_id=? AND status='active'");
        $active->bind_param("s",$sid); $active->execute();
        if ($active->get_result()->num_rows > 0) error('Student is already inside.');
        $now = date('Y-m-d H:i:s');
        $ins = $db->prepare("INSERT INTO visits (student_id,visit_reason,entry_time,status) VALUES (?,?,?,'active')");
        $ins->bind_param("sss",$sid,$visitReason,$now); $ins->execute();
        $vid = $db->insert_id;
        foreach ($activities as $act) {
            $act = trim($act); if (!$act) continue;
            $sa  = $db->prepare("INSERT INTO visit_activities (visit_id,activity) VALUES (?,?)");
            $sa->bind_param("is",$vid,$act); $sa->execute();
        }
        logAction($db,'Entry Approved',"{$student['name']} ($sid) entered. Reason: $visitReason");
        success();
        break;

    case 'deny_entry':
        requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true);
        $sid  = strtoupper(trim($body['student_id'] ?? ''));
        if (!$sid) error('ID required.');
        $db  = getDB();
        $chk = $db->prepare("SELECT name FROM students WHERE id=?");
        $chk->bind_param("s",$sid); $chk->execute();
        $s   = $chk->get_result()->fetch_assoc();
        logAction($db,'Entry Denied',($s['name']??$sid)." ($sid) denied.");
        success();
        break;

    case 'mark_exit':
        requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true);
        $sid  = strtoupper(trim($body['student_id'] ?? ''));
        if (!$sid) error('ID required.');
        $db   = getDB();
        $now  = date('Y-m-d H:i:s');
        $stmt = $db->prepare("UPDATE visits SET status='exited',exit_time=? WHERE student_id=? AND status='active'");
        $stmt->bind_param("ss",$now,$sid); $stmt->execute();
        if ($stmt->affected_rows === 0) error('No active visit found.');
        logAction($db,'Student Exit',"$sid exited.");
        success();
        break;

    case 'student_checkin':
        $body        = json_decode(file_get_contents('php://input'), true);
        $sid         = strtoupper(trim($body['student_id']  ?? ''));
        $visitReason = trim($body['visit_reason'] ?? 'Library Visit');
        if (!$sid) error('Student ID required.');
        $db  = getDB();
        $chk = $db->prepare("SELECT * FROM students WHERE id=?");
        $chk->bind_param("s",$sid); $chk->execute();
        $student = $chk->get_result()->fetch_assoc();
        if (!$student) error('Student ID not found. Please register first.');

        if (!empty($student['blocked']) && $student['blocked'] == 1) {
            error('Access denied. Your library access has been blocked. Please contact the librarian.');
        }

        $aq  = $db->prepare("SELECT id FROM visits WHERE student_id=? AND status='active'");
        $aq->bind_param("s",$sid); $aq->execute();
        $av  = $aq->get_result()->fetch_assoc();
        $now = date('Y-m-d H:i:s');
        if ($av) {
            $upd = $db->prepare("UPDATE visits SET status='exited',exit_time=? WHERE id=?");
            $upd->bind_param("si",$now,$av['id']); $upd->execute();
            logAction($db,'Check-Out',"{$student['name']} ($sid) checked out");
            success(['action'=>'checkout','message'=>"Goodbye, {$student['name']}!",'student'=>$student,'time'=>$now]);
        } else {
            $ins = $db->prepare("INSERT INTO visits (student_id,visit_reason,entry_time,status) VALUES (?,?,?,'active')");
            $ins->bind_param("sss",$sid,$visitReason,$now); $ins->execute();
            $vid = $db->insert_id;
            $sa  = $db->prepare("INSERT INTO visit_activities (visit_id,activity) VALUES (?,?)");
            $sa->bind_param("is",$vid,$visitReason); $sa->execute();
            logAction($db,'Check-In',"{$student['name']} ($sid) checked in - $visitReason");
            success(['action'=>'checkin','message'=>"Welcome to NEU Library, {$student['name']}!",'student'=>$student,'time'=>$now]);
        }
        break;

    case 'get_visits':
        requireLogin();
        $db     = getDB();
        $filter = $_GET['filter'] ?? 'all';
        $search = trim($_GET['search'] ?? '');
        $sql    = "SELECT v.*, s.name as student_name, s.college, s.employee_type,
                   GROUP_CONCAT(va.activity ORDER BY va.id SEPARATOR ', ') as activities
                   FROM visits v JOIN students s ON v.student_id=s.id
                   LEFT JOIN visit_activities va ON va.visit_id=v.id";
        $where=[]; $params=[]; $types='';
        if ($filter==='active') $where[]="v.status='active'";
        if ($filter==='exited') $where[]="v.status='exited'";
        if ($search) { $where[]="(s.name LIKE ? OR v.student_id LIKE ? OR v.visit_reason LIKE ? OR va.activity LIKE ?)"; $like="%$search%"; $params[]=$like; $params[]=$like; $params[]=$like; $params[]=$like; $types.='ssss'; }
        if ($where) $sql.=" WHERE ".implode(' AND ',$where);
        $sql.=" GROUP BY v.id ORDER BY v.entry_time DESC LIMIT 300";
        $stmt=$db->prepare($sql);
        if ($params) $stmt->bind_param($types,...$params);
        $stmt->execute();
        success(['visits'=>$stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        break;

    case 'get_student_visits':
        requireLogin();
        $db  = getDB();
        $sid = strtoupper(trim($_GET['student_id'] ?? ''));
        if (!$sid) error('ID required.');
        $stmt = $db->prepare("SELECT v.*, GROUP_CONCAT(va.activity ORDER BY va.id SEPARATOR ', ') as activities
            FROM visits v LEFT JOIN visit_activities va ON va.visit_id=v.id
            WHERE v.student_id=? GROUP BY v.id ORDER BY v.entry_time DESC");
        $stmt->bind_param("s",$sid); $stmt->execute();
        success(['visits'=>$stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        break;

    case 'get_active_visits':
        $db  = getDB();
        $rows = $db->query("SELECT v.*, s.name as student_name, s.college, s.department, s.employee_type
            FROM visits v JOIN students s ON v.student_id=s.id
            WHERE v.status='active' ORDER BY v.entry_time DESC")->fetch_all(MYSQLI_ASSOC);
        success(['visits'=>$rows]);
        break;

    case 'get_today_visits':
        $db    = getDB();
        $today = date('Y-m-d');
        $rows  = $db->query("SELECT v.*, s.name as student_name, s.college, s.department, s.employee_type,
            GROUP_CONCAT(va.activity ORDER BY va.id SEPARATOR ', ') as activities
            FROM visits v JOIN students s ON v.student_id=s.id
            LEFT JOIN visit_activities va ON va.visit_id=v.id
            WHERE DATE(v.entry_time)='$today'
            GROUP BY v.id ORDER BY v.entry_time DESC")->fetch_all(MYSQLI_ASSOC);
        success(['visits'=>$rows]);
        break;

    case 'get_recent_visits':
        $db  = getDB();
        $rows = $db->query("SELECT v.*, s.name as student_name,
            GROUP_CONCAT(va.activity ORDER BY va.id SEPARATOR ', ') as activities
            FROM visits v JOIN students s ON v.student_id=s.id
            LEFT JOIN visit_activities va ON va.visit_id=v.id
            GROUP BY v.id ORDER BY v.entry_time DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);
        success(['visits'=>$rows]);
        break;

    case 'get_dashboard_stats':
        requireLogin();
        $db    = getDB();
        $today = date('Y-m-d');
        $total_students = $db->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];
        $today_visits   = $db->query("SELECT COUNT(*) as c FROM visits WHERE DATE(entry_time)='$today'")->fetch_assoc()['c'];
        $active_visits  = $db->query("SELECT COUNT(*) as c FROM visits WHERE status='active'")->fetch_assoc()['c'];
        $total_visits   = $db->query("SELECT COUNT(*) as c FROM visits")->fetch_assoc()['c'];
        success(['stats' => compact('total_students','today_visits','active_visits','total_visits')]);
        break;
    
    case 'get_stats':
        requireAdmin();
        $db        = getDB();
        $today     = date('Y-m-d');
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $dateFrom  = $_GET['date_from']     ?? $today;
        $dateTo    = $_GET['date_to']       ?? $today;
        $reason    = $_GET['visit_reason']  ?? '';
        $college   = $_GET['college']       ?? '';
        $empType   = $_GET['employee_type'] ?? '';

        $where = ["DATE(v.entry_time) BETWEEN ? AND ?"]; $params = [$dateFrom,$dateTo]; $types = 'ss';
        if ($reason)  { $where[]="v.visit_reason=?";    $params[]=$reason;  $types.='s'; }
        if ($college) { $where[]="s.college=?";         $params[]=$college; $types.='s'; }
        if ($empType) { $where[]="s.employee_type=?";   $params[]=$empType; $types.='s'; }
        $w = implode(' AND ', $where);

        $q1=$db->prepare("SELECT COUNT(*) as c FROM visits v JOIN students s ON v.student_id=s.id WHERE $w");
        $q1->bind_param($types,...$params); $q1->execute();
        $totalVisits = $q1->get_result()->fetch_assoc()['c'];

        $q2=$db->prepare("SELECT COUNT(DISTINCT v.student_id) as c FROM visits v JOIN students s ON v.student_id=s.id WHERE $w");
        $q2->bind_param($types,...$params); $q2->execute();
        $uniqueVisitors = $q2->get_result()->fetch_assoc()['c'];

        $todayCount   = $db->query("SELECT COUNT(*) as c FROM visits WHERE DATE(entry_time)='$today'")->fetch_assoc()['c'];
        $weekCount    = $db->query("SELECT COUNT(*) as c FROM visits WHERE DATE(entry_time)>='$weekStart'")->fetch_assoc()['c'];
        $activeCount  = $db->query("SELECT COUNT(*) as c FROM visits WHERE status='active'")->fetch_assoc()['c'];
        $totalStudents= $db->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];

        $q7=$db->prepare("SELECT v.visit_reason, COUNT(*) as count FROM visits v JOIN students s ON v.student_id=s.id WHERE $w GROUP BY v.visit_reason ORDER BY count DESC");
        $q7->bind_param($types,...$params); $q7->execute(); $byReason=$q7->get_result()->fetch_all(MYSQLI_ASSOC);

        $q8=$db->prepare("SELECT s.college, COUNT(*) as count FROM visits v JOIN students s ON v.student_id=s.id WHERE $w GROUP BY s.college ORDER BY count DESC");
        $q8->bind_param($types,...$params); $q8->execute(); $byCollege=$q8->get_result()->fetch_all(MYSQLI_ASSOC);

        $q9=$db->prepare("SELECT s.employee_type, COUNT(*) as count FROM visits v JOIN students s ON v.student_id=s.id WHERE $w GROUP BY s.employee_type ORDER BY count DESC");
        $q9->bind_param($types,...$params); $q9->execute(); $byEmpType=$q9->get_result()->fetch_all(MYSQLI_ASSOC);

        $q10=$db->prepare("SELECT DATE(v.entry_time) as date, COUNT(*) as count FROM visits v JOIN students s ON v.student_id=s.id WHERE $w GROUP BY DATE(v.entry_time) ORDER BY date ASC");
        $q10->bind_param($types,...$params); $q10->execute(); $daily=$q10->get_result()->fetch_all(MYSQLI_ASSOC);

        success(['stats'=>compact('totalVisits','uniqueVisitors','todayCount','weekCount','activeCount','totalStudents','byReason','byCollege','byEmpType','daily')]);
        break;

    case 'get_filter_options':
        requireAdmin();
        $db      = getDB();
        $reasons = $db->query("SELECT DISTINCT visit_reason FROM visits ORDER BY visit_reason")->fetch_all(MYSQLI_ASSOC);
        $colleges= $db->query("SELECT DISTINCT college FROM students ORDER BY college")->fetch_all(MYSQLI_ASSOC);
        success(['reasons'=>$reasons,'colleges'=>$colleges]);
        break;

    case 'get_logs':
        requireAdmin();
        $db  = getDB();
        $rows= $db->query("SELECT * FROM activity_log ORDER BY logged_at DESC LIMIT 300")->fetch_all(MYSQLI_ASSOC);
        success(['logs'=>$rows]);
        break;

    default:
        error("Unknown action: $action", 404);
}
?>
