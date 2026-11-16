<?php
/**
 * ParkingLog model - check-in / check-out, receipts, and reports.
 * Fee: PHP 20.00 per hour (any part of an hour counts as a full hour, minimum 1 hour).
 */
class ParkingLog
{
    public const RATE_PER_HOUR = 20.0;

    private const SELECT_RECEIPT =
        "SELECT l.log_id, l.slot_id, l.plate_number, l.time_in, l.time_out, l.status,
                s.slot_number, u.username AS operator
         FROM parking_logs l
         JOIN parking_slots s ON s.slot_id = l.slot_id
         JOIN users u ON u.id = l.operator_id";

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Fee SQL expression (for completed logs). */
    private function feeExpr(): string
    {
        return "GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, time_in, time_out) / 3600)) * " . (float)self::RATE_PER_HOUR;
    }

    /** CHECK IN. If $slotId is null, auto-assign the first available slot. */
    public function checkIn(?int $slotId, string $plate, int $operatorId): array
    {
        $plate = strtoupper(trim($plate));
        if (!preg_match('/^[A-Z0-9 \-]{2,15}$/', $plate)) {
            throw new RuntimeException('Invalid plate number (2-15 letters/numbers).', 400);
        }

        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT 1 FROM parking_logs WHERE plate_number = :p AND status = 'parked' LIMIT 1");
            $st->execute([':p' => $plate]);
            if ($st->fetchColumn()) {
                throw new RuntimeException("Plate $plate is already parked.", 409);
            }

            if ($slotId !== null) {
                $st = $this->db->prepare("SELECT slot_id, status FROM parking_slots WHERE slot_id = :id FOR UPDATE");
                $st->execute([':id' => $slotId]);
                $slot = $st->fetch();
                if (!$slot) {
                    throw new RuntimeException('Slot not found.', 404);
                }
            } else {
                $slot = $this->db->query(
                    "SELECT slot_id, status FROM parking_slots WHERE status = 'available' ORDER BY slot_id LIMIT 1 FOR UPDATE"
                )->fetch();
                if (!$slot) {
                    throw new RuntimeException('No available slots.', 409);
                }
            }
            if ($slot['status'] !== 'available') {
                throw new RuntimeException('This slot is not available.', 409);
            }

            $ins = $this->db->prepare(
                "INSERT INTO parking_logs (slot_id, plate_number, time_in, operator_id, status)
                 VALUES (:s, :p, :t, :o, 'parked')"
            );
            $ins->execute([
                ':s' => $slot['slot_id'], ':p' => $plate,
                ':t' => date('Y-m-d H:i:s'), ':o' => $operatorId,
            ]);
            $logId = (int)$this->db->lastInsertId();

            $up = $this->db->prepare("UPDATE parking_slots SET status = 'occupied' WHERE slot_id = :id");
            $up->execute([':id' => $slot['slot_id']]);

            $this->db->commit();
            return $this->getReceipt($logId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** CHECK OUT using log_id OR plate number. */
    public function checkOut(?int $logId, ?string $plate): array
    {
        $this->db->beginTransaction();
        try {
            if ($logId !== null) {
                $st = $this->db->prepare("SELECT log_id, slot_id FROM parking_logs WHERE log_id = :id AND status = 'parked' FOR UPDATE");
                $st->execute([':id' => $logId]);
            } elseif ($plate !== null && trim($plate) !== '') {
                $st = $this->db->prepare("SELECT log_id, slot_id FROM parking_logs WHERE plate_number = :p AND status = 'parked' FOR UPDATE");
                $st->execute([':p' => strtoupper(trim($plate))]);
            } else {
                throw new RuntimeException('log_id or plate_number is required.', 400);
            }
            $log = $st->fetch();
            if (!$log) {
                throw new RuntimeException('No active parking record found for this.', 404);
            }

            $up = $this->db->prepare("UPDATE parking_logs SET time_out = :t, status = 'completed' WHERE log_id = :id");
            $up->execute([':t' => date('Y-m-d H:i:s'), ':id' => $log['log_id']]);

            $slotUp = $this->db->prepare("UPDATE parking_slots SET status = 'available' WHERE slot_id = :id AND status = 'occupied'");
            $slotUp->execute([':id' => $log['slot_id']]);

            $this->db->commit();
            return $this->getReceipt((int)$log['log_id']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getReceipt(int $logId): ?array
    {
        $st = $this->db->prepare(self::SELECT_RECEIPT . " WHERE l.log_id = :id");
        $st->execute([':id' => $logId]);
        $row = $st->fetch();
        return $row ? $this->formatReceipt($row) : null;
    }

    /**
     * Logs (receipt format) with optional filters:
     *  $status = 'parked' | 'completed'
     *  $search = part of plate number or slot number
     *  $from   = 'YYYY-MM-DD' (time_in from this date)
     */
    public function getRecent(int $limit = 50, ?string $status = null, ?string $search = null, ?string $from = null): array
    {
        $limit  = max(1, min(200, $limit));
        $where  = [];
        $params = [];

        if ($status !== null && in_array($status, ['parked', 'completed'], true)) {
            $where[] = 'l.status = :status';
            $params[':status'] = $status;
        }
        if ($search !== null && trim($search) !== '') {
            $like = '%' . trim($search) . '%';
            $where[] = '(l.plate_number LIKE :q1 OR s.slot_number LIKE :q2)';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
        }
        if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'l.time_in >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }

        $sql = self::SELECT_RECEIPT;
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.log_id DESC LIMIT ' . $limit;

        $st = $this->db->prepare($sql);
        $st->execute($params);
        return array_map([$this, 'formatReceipt'], $st->fetchAll());
    }

    private function formatReceipt(array $r): array
    {
        $in      = strtotime($r['time_in']);
        $out     = $r['time_out'] ? strtotime($r['time_out']) : time();
        $seconds = max(0, $out - $in);
        $minutes = max(1, (int)ceil($seconds / 60));
        $hours   = max(1, (int)ceil($seconds / 3600));

        return [
            'log_id'           => (int)$r['log_id'],
            'slot_id'          => (int)$r['slot_id'],
            'slot_number'      => $r['slot_number'],
            'plate_number'     => $r['plate_number'],
            'operator'         => $r['operator'],
            'time_in'          => $r['time_in'],
            'time_out'         => $r['time_out'],
            'status'           => $r['status'],
            'duration_minutes' => $minutes,
            'billed_hours'     => $hours,
            'rate_per_hour'    => self::RATE_PER_HOUR,
            'fee'              => $hours * self::RATE_PER_HOUR,
        ];
    }

    /** Daily summary for the operator (their own transactions) + total slot info. */
    public function getTodaySummary(int $operatorId, ParkingSlot $slots): array
    {
        $today = date('Y-m-d');

        $st = $this->db->prepare("SELECT COUNT(*) FROM parking_logs WHERE operator_id = :o AND DATE(time_in) = :d");
        $st->execute([':o' => $operatorId, ':d' => $today]);
        $checkedIn = (int)$st->fetchColumn();

        $st = $this->db->prepare(
            "SELECT COALESCE(SUM(" . $this->feeExpr() . "), 0) FROM parking_logs
             WHERE operator_id = :o AND status = 'completed' AND DATE(time_out) = :d"
        );
        $st->execute([':o' => $operatorId, ':d' => $today]);
        $revenue = (float)$st->fetchColumn();

        $parked = (int)$this->db->query("SELECT COUNT(*) FROM parking_logs WHERE status = 'parked'")->fetchColumn();
        $s      = $slots->summary();

        return [
            'checked_in_today' => $checkedIn,
            'revenue_today'    => $revenue,
            'currently_parked' => $parked,
            'available_slots'  => $s['available'],
            'total_slots'      => $s['total'],
        ];
    }

    /** Overall report for admin. */
    public function getReport(ParkingSlot $slots): array
    {
        $feeExpr = $this->feeExpr();
        $today   = date('Y-m-d');

        $all = $this->db->query(
            "SELECT COUNT(*) AS c, COALESCE(SUM($feeExpr), 0) AS r FROM parking_logs WHERE status = 'completed'"
        )->fetch();

        $st = $this->db->prepare(
            "SELECT COUNT(*) AS c, COALESCE(SUM($feeExpr), 0) AS r FROM parking_logs
             WHERE status = 'completed' AND DATE(time_out) = :d"
        );
        $st->execute([':d' => $today]);
        $outToday = $st->fetch();

        $st = $this->db->prepare("SELECT COUNT(*) FROM parking_logs WHERE DATE(time_in) = :d");
        $st->execute([':d' => $today]);
        $inToday = (int)$st->fetchColumn();

        $parked = (int)$this->db->query("SELECT COUNT(*) FROM parking_logs WHERE status = 'parked'")->fetchColumn();

        $perOp = $this->db->query(
            "SELECT u.username, COUNT(l.log_id) AS transactions
             FROM users u LEFT JOIN parking_logs l ON l.operator_id = u.id
             WHERE u.role = 'operator'
             GROUP BY u.id, u.username ORDER BY u.id"
        )->fetchAll();
        foreach ($perOp as &$p) {
            $p['transactions'] = (int)$p['transactions'];
        }
        unset($p);

        // Revenue for the past 7 days (including today)
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $days[$d] = ['date' => $d, 'revenue' => 0.0, 'vehicles' => 0];
        }
        $from = array_key_first($days);
        $st = $this->db->prepare(
            "SELECT DATE(time_out) AS d, COUNT(*) AS c, COALESCE(SUM($feeExpr), 0) AS r
             FROM parking_logs
             WHERE status = 'completed' AND DATE(time_out) >= :from
             GROUP BY DATE(time_out)"
        );
        $st->execute([':from' => $from]);
        foreach ($st->fetchAll() as $row) {
            $key = (string)$row['d'];
            if (isset($days[$key])) {
                $days[$key]['revenue']  = (float)$row['r'];
                $days[$key]['vehicles'] = (int)$row['c'];
            }
        }

        return [
            'slots'            => $slots->summary(),
            'today'            => ['vehicles_in' => $inToday, 'vehicles_out' => (int)$outToday['c'], 'revenue' => (float)$outToday['r']],
            'overall'          => ['transactions' => (int)$all['c'], 'revenue' => (float)$all['r']],
            'currently_parked' => $parked,
            'per_operator'     => $perOp,
            'last_7_days'      => array_values($days),
            'recent'           => $this->getRecent(20),
        ];
    }
}