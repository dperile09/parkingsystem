<?php
require_once __DIR__ . '/../config/Api.php';
require_once __DIR__ . '/../models/ParkingSlot.php';
require_once __DIR__ . '/../models/ParkingLog.php';

Api::init();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Api::respond(405, ['success' => false, 'message' => 'Only GET is allowed.']);
}

Api::authenticate(['admin']);

$db     = Api::db();
$report = (new ParkingLog($db))->getReport(new ParkingSlot($db));

Api::respond(200, ['success' => true, 'report' => $report]);