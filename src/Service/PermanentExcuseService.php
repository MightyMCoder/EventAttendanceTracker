<?php
/**
 * Manages dated, configuration-specific default excuses.
 *
 * @copyright MightyMCoder
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;

final class PermanentExcuseService
{
    private const RULES = TABLE_PREFIX . '_event_attendance_excuses';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Build the validity predicate for an occurrence and the rule's historical lifetime.
     *
     * SQL expressions are internal column names, never request parameters.
     */
    public static function condition(string $tracker, string $user, string $begin, string $end = 'COALESCE(dat_end, dat_begin)'): string
    {
        return 'EXISTS (SELECT 1 FROM ' . self::RULES . ' AS excuse
            WHERE excuse.eax_tracker_id = ' . $tracker . ' AND excuse.eax_usr_id = ' . $user . '
              AND DATE(' . $begin . ') >= excuse.eax_date_from
              AND (excuse.eax_date_until IS NULL OR DATE(' . $begin . ') <= excuse.eax_date_until)
              AND ' . $end . ' >= excuse.eax_created_at
              AND (excuse.eax_revoked_at IS NULL OR ' . $end . ' <= excuse.eax_revoked_at))';
    }

    /** Resolve a manual record first, including explicit clearing, before applying a default. */
    public static function status(string $tracker, string $user, string $begin, string $end = 'COALESCE(dat_end, dat_begin)'): string
    {
        return "CASE WHEN ear_id IS NOT NULL THEN NULLIF(ear_status, '') WHEN "
            . self::condition($tracker, $user, $begin, $end) . " THEN 'excused' ELSE NULL END";
    }

    /** Return the active editor rows without discarding revoked historical rules. */
    public function findAll(int $trackerId): array
    {
        return $this->database->queryPrepared(
            'SELECT eax_id, eax_usr_id, eax_date_from, eax_date_until FROM ' . self::RULES . '
             WHERE eax_tracker_id = ? AND eax_revoked_at IS NULL ORDER BY eax_date_from, eax_id',
            array($trackerId)
        )->fetchAll();
    }

    /** Validate the inclusive date range and eligible role membership before adding a rule. */
    public function add(int $trackerId, int $userId, string $from, ?string $until): int
    {
        foreach (array($from, $until) as $date) {
            if ($date === null) {
                continue;
            }
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('Invalid date.');
            }
        }
        if ($until !== null && $until < $from) {
            throw new \InvalidArgumentException('The end date must not precede the start date.');
        }
        $eligible = $this->database->queryPrepared(
            'SELECT mem_usr_id FROM ' . TABLE_PREFIX . '_event_attendance_tracker_roles
             INNER JOIN ' . TBL_MEMBERS . ' ON mem_rol_id = etr_rol_id
             WHERE etr_tracker_id = ? AND mem_usr_id = ? AND mem_end > ?
               AND (mem_approved IS NULL OR mem_approved < 3)',
            array($trackerId, $userId, $from)
        )->fetch();
        if ($eligible === false) {
            throw new \InvalidArgumentException('Person does not belong to the configured roles.');
        }
        $this->database->queryPrepared(
            'INSERT INTO ' . self::RULES . ' (eax_tracker_id, eax_usr_id, eax_date_from, eax_date_until) VALUES (?, ?, ?, ?)',
            array($trackerId, $userId, $from, $until)
        );
        return $this->database->lastInsertId();
    }

    /** Replace only the future portion; retain the old rule for historical events. */
    public function update(int $trackerId, int $ruleId, int $userId, string $from, ?string $until): int
    {
        $this->database->startTransaction();
        try {
            $existing = $this->database->queryPrepared(
                'SELECT eax_id FROM ' . self::RULES . ' WHERE eax_tracker_id = ? AND eax_id = ? AND eax_revoked_at IS NULL',
                array($trackerId, $ruleId)
            )->fetch();
            if ($existing === false) {
                throw new \InvalidArgumentException('Permanent excuse no longer exists.');
            }
            $newId = $this->add($trackerId, $userId, $from, $until);
            $this->end($trackerId, $ruleId);
            $this->database->endTransaction();
            return $newId;
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    /** Apply all staged rows atomically when the configuration is saved. */
    public function synchronize(int $trackerId, array $rows): void
    {
        if (!array_is_list($rows)) {
            throw new \InvalidArgumentException('Invalid permanent-excuse list.');
        }
        $existing = array_column($this->findAll($trackerId), null, 'eax_id');
        $seen = array();
        $this->database->startTransaction();
        try {
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['id'], $row['user'], $row['from'])
                    || !is_int($row['id']) || $row['id'] < 0 || !is_int($row['user']) || !is_string($row['from'])
                    || (isset($row['until']) && !is_string($row['until']))) {
                    throw new \InvalidArgumentException('Invalid permanent-excuse row.');
                }
                $id = $row['id'];
                $until = $row['until'] ?? null;
                if ($id === 0) {
                    $this->add($trackerId, $row['user'], $row['from'], $until);
                    continue;
                }
                if (!isset($existing[$id]) || isset($seen[$id])) {
                    throw new \InvalidArgumentException('Permanent excuse no longer exists.');
                }
                $seen[$id] = true;
                $old = $existing[$id];
                if ((int) $old['eax_usr_id'] !== $row['user'] || $old['eax_date_from'] !== $row['from'] || $old['eax_date_until'] !== $until) {
                    $this->update($trackerId, $id, $row['user'], $row['from'], $until);
                }
            }
            foreach ($existing as $id => $rule) {
                if (!isset($seen[$id])) {
                    $this->end($trackerId, (int) $id);
                }
            }
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    /** Retain the historical rule for completed events. */
    public function end(int $trackerId, int $ruleId): void
    {
        $this->database->queryPrepared(
            'UPDATE ' . self::RULES . ' SET eax_revoked_at = CURRENT_TIMESTAMP
             WHERE eax_tracker_id = ? AND eax_id = ? AND eax_revoked_at IS NULL',
            array($trackerId, $ruleId)
        );
    }
}
