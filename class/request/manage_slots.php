<?php
/**
 * /request/manage_slots.php[cite: 21]
 *  GET    (admin/operator)  -> list of slots + summary (real-time monitoring)[cite: 21]
 *  POST   (admin) {"slot_number":"A-1"}  -> add a slot[cite: 21]
 *  POST   (admin) {"capacity": 20}       -> set total capacity[cite: 21]
 *  PUT    (admin) {"slot_id":1,"status":"maintenance"} -> change status[cite: 21]
 *  DELETE (admin) ?slot_id=1             -> delete slot[cite: 21]
 */
require_once __DIR__ . '/../config/Api.php';
require_once __DIR__ . '/../models/ParkingSlot.php';

Api::init();

$method = $_SERVER['REQUEST_METHOD'];
$slots  = new ParkingSlot(Api::db());

try {
    switch ($method) {
        case 'GET':
            Api::authenticate(['admin', 'operator']);
            Api::respond(200, [
                'success' => true,
                'slots'   => $slots->getAll(),
                'summary' => $slots->summary(),
            ]);
            break;

        case 'POST':
            Api::authenticate(['admin']);
            $in = Api::input();

            // --- Set capacity ---
            if (isset($in['capacity'])) {
                $cap = (int)$in['capacity'];
                if ($cap < 0 || $cap > 500) {
                    Api::respond(400, ['success' => false, 'message' => 'Capacity must be between 0 and 500.']);
                }
                $r   = $slots->setCapacity($cap);
                $msg = "Capacity: {$r['total']} slots (+{$r['added']} / -{$r['removed']}).";
                if ($r['total'] !== $cap) {
                    $msg .= ' Not all removed: occupied slots or slots with history will not be deleted (set to maintenance instead).';
                }
                Api::respond(200, ['success' => true, 'message' => $msg, 'result' => $r]);
            }

            // --- Add a single slot ---
            $number = trim((string)($in['slot_number'] ?? ''));
            if ($number === '' || strlen($number) > 20) {
                Api::respond(400, ['success' => false, 'message' => 'Provide slot number (max 20 chars).']);
            }
            if ($slots->slotNumberExists($number)) {
                Api::respond(409, ['success' => false, 'message' => 'That slot number already exists.']);
            }
            $id = $slots->create($number);
            Api::respond(201, ['success' => true, 'message' => 'Slot added.', 'slot_id' => $id]);
            break;

        case 'PUT':
            Api::authenticate(['admin']);
            $in     = Api::input();
            $id     = (int)($in['slot_id'] ?? 0);
            $status = (string)($in['status'] ?? '');

            $slot = $slots->getById($id);
            if (!$slot) {
                Api::respond(404, ['success' => false, 'message' => 'Slot not found.']);
            }
            if (!in_array($status, ['available', 'maintenance'], true)) {
                Api::respond(400, ['success' => false, 'message' => "Status can only be 'available' or 'maintenance' ('occupied' is automatic on check-in)."]);
            }
            if ($slot['status'] === 'occupied') {
                Api::respond(409, ['success' => false, 'message' => 'A vehicle is parked in this slot. Check out first.']);
            }
            $slots->updateStatus($id, $status);
            Api::respond(200, ['success' => true, 'message' => 'Slot updated.']);
            break;

        case 'DELETE':
            Api::authenticate(['admin']);
            $in   = Api::input();
            $id   = (int)($_GET['slot_id'] ?? ($in['slot_id'] ?? 0));
            $slot = $slots->getById($id);
            if (!$slot) {
                Api::respond(404, ['success' => false, 'message' => 'Slot not found.']);
            }
            if ($slot['status'] === 'occupied') {
                Api::respond(409, ['success' => false, 'message' => 'A vehicle is parked in this slot.']);
            }
            $slots->delete($id);
            Api::respond(200, ['success' => true, 'message' => 'Slot deleted.']);
            break;

        default:
            Api::respond(405, ['success' => false, 'message' => 'Method not supported.']);
    }
} catch (PDOException $e) {
    // 23000 = foreign key / duplicate
    if ((string)$e->getCode() === '23000') {
        Api::respond(409, ['success' => false, 'message' => 'Cannot delete: this slot has parking history. Set to maintenance instead.']);
    }
    throw $e;
}
?>