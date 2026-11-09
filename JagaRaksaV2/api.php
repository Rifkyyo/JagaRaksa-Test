<?php
require_once __DIR__ . '/config.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['a'] ?? '') === 'read_notif') {
    csrf_check();
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
    redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
}
if (($_GET['a'] ?? '') === 'markers') {
    header('Content-Type: application/json; charset=utf-8');
    $scope = is_staff($user) ? '' : 'WHERE user_id = ' . (int)$user['id'];
    echo json_encode(db()->query("SELECT ticket_code, title, address, latitude lat, longitude lng, status, category_id FROM reports $scope")->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}
http_response_code(404);
echo 'Not found';
