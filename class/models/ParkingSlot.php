<?php
/**
 * ParkingSlot model - CRUD and capacity management for `parking_slots`.
 */
class ParkingSlot
{
    public const STATUSES = ['available', 'occupied', 'maintenance'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** READ ALL - including currently parked vehicle (plate, time_in) if occupied. */
    public function getAll(): array
    {
        $sql = "SELECT s.slot_id, s.slot_number, s.status,
                       l.log_id, l.plate_number, l.time_in
                FROM parking_slots s
                LEFT JOIN parking_logs l ON l.slot_id = s.slot_id AND l.status = 'parked'
                ORDER BY s.slot_id";
        return $this->db->query($sql)->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $st = $this->db->prepare("SELECT slot_id, slot_number, status FROM parking_slots WHERE slot_id = :id");
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function slotNumberExists(string $number, int $excludeId = 0): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM parking_slots WHERE slot_number = :n AND slot_id <> :id LIMIT 1");
        $st->execute([':n' => $number, ':id' => $excludeId]);
        return (bool)$st->fetchColumn();
    }

    /** CREATE */
    public function create(string $slotNumber, string $status = 'available'): int
    {
        $st = $this->db->prepare("INSERT INTO parking_slots (slot_number, status) VALUES (:n, :s)");
        $st->execute([':n' => $slotNumber, ':s' => $status]);
        return (int)$this->db->lastInsertId();
    }

    /** UPDATE status */
    public function updateStatus(int $id, string $status): bool
    {
        $st = $this->db->prepare("UPDATE parking_slots SET status = :s WHERE slot_id = :id");
        return $st->execute([':s' => $status, ':id' => $id]);
    }

    /** DELETE */
    public function delete(int $id): bool
    {
        $st = $this->db->prepare("DELETE FROM parking_slots WHERE slot_id = :id");
        $st->execute([':id' => $id]);
        return $st->rowCount() > 0;
    }

    /** Count of slots per status + total. */
    public function summary(): array
    {
        $out = ['total' => 0, 'available' => 0, 'occupied' => 0, 'maintenance' => 0];
        $rows = $this->db->query("SELECT status, COUNT(*) AS c FROM parking_slots GROUP BY status")->fetchAll();
        foreach ($rows as $r) {
            $out[$r['status']] = (int)$r['c'];
            $out['total'] += (int)$r['c'];
        }
        return $out;
    }

    /**
     * Set total capacity (number of slots).
     * - If larger: creates new slots (P-001, P-002, ...).
     * - If smaller: removes available slots with NO history.
     *   (Occupied slots or slots with logs cannot be deleted.)
     */
    public function setCapacity(int $target): array
    {
        $this->db->beginTransaction();
        try {
            $total   = (int)$this->db->query("SELECT COUNT(*) FROM parking_slots")->fetchColumn();
            $added   = 0;
            $removed = 0;

            if ($target > $total) {
                $i = 1;
                while ($total + $added < $target) {
                    $num = sprintf('P-%03d', $i++);
                    if ($this->slotNumberExists($num)) {
                        continue;
                    }
                    $this->create($num);
                    $added++;
                }
            } elseif ($target < $total) {
                $toRemove = (int)($total - $target);
                $ids = $this->db->query(
                    "SELECT slot_id FROM parking_slots
                     WHERE status = 'available'
                       AND slot_id NOT IN (SELECT slot_id FROM parking_logs)
                     ORDER BY slot_id DESC LIMIT " . $toRemove
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($ids as $id) {
                    $this->delete((int)$id);
                    $removed++;
                }
            }

            $this->db->commit();
            return ['total' => $total + $added - $removed, 'added' => $added, 'removed' => $removed];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}