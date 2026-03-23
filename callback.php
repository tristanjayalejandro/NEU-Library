<?php
session_start();
require_once '../config.php';
require_once '../db.php';

if (!isset($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
    header('Location: ../index.html?error=invalid_state');
    exit;
}
unset($_SESSION['oauth_state']);

if (isset($_GET['error'])) {
    header('Location: ../index.html?error=access_denied');
    exit;
}

$code = $_GET['code'] ?? '';
if (!$code) {
    header('Location: ../index.html?error=no_code');
    exit;
}

$tokenRes = curlPost('https://oauth2.googleapis.com/token', [
    'code'          => $code,
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);

if (empty($tokenRes['access_token'])) {
    header('Location: ../index.html?error=token_failed');
    exit;
}

$userInfo = curlGet('https://www.googleapis.com/oauth2/v3/userinfo', $tokenRes['access_token']);

if (empty($userInfo['email'])) {
    header('Location: ../index.html?error=no_email');
    exit;
}

$email    = strtolower(trim($userInfo['email']));
$name     = $userInfo['name']    ?? $email;
$googleId = $userInfo['sub']     ?? '';
$avatar   = $userInfo['picture'] ?? '';

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM allowed_users WHERE email = ? AND is_active = 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user && in_array($email, ADMIN_EMAILS)) {
    $role   = 'admin';
    $pass   = md5(bin2hex(random_bytes(8))); 
    $ins    = $db->prepare("INSERT INTO allowed_users (email, password, name, role, active_role, is_active) VALUES (?,?,?,?,?,1)");
    $ins->bind_param("sssss", $email, $pass, $name, $role, $role);
    $ins->execute();

    $stmt = $db->prepare("SELECT * FROM allowed_users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
}

if (!$user) {
    header('Location: ../index.html?error=not_authorized');
    exit;
}

$upd = $db->prepare("UPDATE allowed_users SET name = ? WHERE id = ?");
$upd->bind_param("si", $name, $user['id']);
$upd->execute();

$_SESSION['user_id']     = $user['id'];
$_SESSION['user_email']  = $email;
$_SESSION['user_name']   = $name;
$_SESSION['user_avatar'] = $avatar;
$_SESSION['user_role']   = $user['role'];
$_SESSION['active_role'] = $user['active_role'];

$logStmt = $db->prepare("INSERT INTO activity_log (action, detail, user_email) VALUES (?,?,?)");
$action  = 'Google Login';
$detail  = "$name ($email) signed in via Google";
$logStmt->bind_param("sss", $action, $detail, $email);
$logStmt->execute();

if ($user['active_role'] === 'admin') {
    header('Location: ../dashboard.html');
} else {
    header('Location: ../welcome.html');
}
exit;

function curlPost($url, $data) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? [];
}

function curlGet($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? [];
}
