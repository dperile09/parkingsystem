<?php
/**
 * POST /request/park_in.php   (OPERATOR only)
 * Body: {"plate_number": "ABC123", "slot_id": 3}   <- slot_id is optional (auto-assign)
 * Response: {"success": true, "receipt": {...}}
 */
require_once __DIR__ . '/../config/Api.php';
require_once __DIR__ . '/../models/ParkingLog.php';

Api::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Api::respond(405, ['success' => false, 'message' => 'Only POST is allowed.']);
}

$user = Api::authenticate(['operator']);
$in   = Api::input();

$plate  = trim((string)($in['plate_number'] ?? ''));
$slotId = isset($in['slot_id']) && $in['slot_id'] !== '' ? (int)$in['slot_id'] : null;

if ($plate === '') {
    Api::respond(400, ['success' => false, 'message' => 'Provide plate number.']);
}

try {
    $receipt = (new ParkingLog(Api::db()))->checkIn($slotId, $plate, (int)$user['id']);
    Api::respond(201, ['success' => true, 'message' => 'Vehicle checked in successfully.', 'receipt' => $receipt]);
} catch (RuntimeException $e) {
    $code = in_array($e->getCode(), [400, 404, 409], true) ? $e->getCode() : 400;
    Api::respond($code, ['success' => false, 'message' => $e->getMessage()]);
}
?>