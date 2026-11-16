<?php

require_once __DIR__ . '/../config/Api.php';
require_once __DIR__ . '/../models/ParkingLog.php';

Api::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Api::respond(405, ['success' => false, 'message' => 'Only POST is allowed.']);
}

Api::authenticate(['operator']);
$in = Api::input();

$logId = isset($in['log_id']) && $in['log_id'] !== '' ? (int)$in['log_id'] : null;
$plate = isset($in['plate_number']) ? (string)$in['plate_number'] : null;

try {
    $receipt = (new ParkingLog(Api::db()))->checkOut($logId, $plate);
    Api::respond(200, ['success' => true, 'message' => 'Vehicle checked out successfully.', 'receipt' => $receipt]);
} catch (RuntimeException $e) {
    $code = in_array($e->getCode(), [400, 404, 409], true) ? $e->getCode() : 400;
    Api::respond($code, ['success' => false, 'message' => $e->getMessage()]);
}
?>