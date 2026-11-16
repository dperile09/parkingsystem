<?php
require_once __DIR__ . '/../config/Api.php';
require_once __DIR__ . '/../models/ParkingLog.php';

Api::init();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Api::respond(405, ['success' => false, 'message' => 'Only GET is allowed.']);
}

Api::authenticate(['admin', 'operator']);
$logs = new ParkingLog(Api::db());

if (isset($_GET['log_id'])) {
    $receipt = $logs->getReceipt((int)$_GET['log_id']);
    if (!$receipt) {
        Api::respond(404, ['success' => false, 'message' => 'Receipt not found.']);
    }
    Api::respond(200, ['success' => true, 'receipt' => $receipt]);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
Api::respond(200, ['success' => true, 'receipts' => $logs->getRecent($limit)]);
?>