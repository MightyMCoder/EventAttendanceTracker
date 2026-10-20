<?php
/**
 * Coordinates exclusive, time-limited attendance recording leases.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Infrastructure\Database;
use DateTimeImmutable;

/**
 * Prevents concurrent editing of the same tracker occurrence.
 */
final class AttendanceLockService
{
    private const LOCKS = TABLE_PREFIX . '_event_attendance_locks';
    private const HEARTBEAT_INTERVAL_SECONDS = 5;

    /**
     * Initialize the service with the configured lease duration.
     */
    public function __construct(
        private readonly Database $database,
        private readonly int $leaseSeconds
    ) {
    }

    /**
     * Return a short interval so an externally released lock is noticed promptly.
     */
    public function getHeartbeatIntervalSeconds(): int
    {
        return self::HEARTBEAT_INTERVAL_SECONDS;
    }

    /**
     * Remove all locks for one organization.
     */
    public function clearAll(int $organizationId): void
    {
        $this->database->queryPrepared(
            'DELETE FROM ' . self::LOCKS . ' WHERE eal_tracker_id IN (
                SELECT eat_id FROM ' . TABLE_PREFIX . '_event_attendance_trackers WHERE eat_org_id = ?
            )',
            array($organizationId)
        );
    }

    /**
     * Acquire the lease for a tracker occurrence.
     *
     * @return array{token: string|null, lockedUserId: int|null}
     */
    public function acquire(int $trackerId, string $eventUuid, int $userId, string $sessionId): array
    {
        $now = new DateTimeImmutable(DATETIME_NOW);
        $sessionHash = $this->getSessionHash($sessionId);
        $token = 'v1' . $sessionHash . bin2hex(random_bytes(16));

        $this->database->startTransaction();
        try {
            $this->database->queryPrepared('DELETE FROM ' . self::LOCKS . ' WHERE eal_expires_at <= ?', array($now->format('Y-m-d H:i:s')));
            $existingLock = $this->database->queryPrepared(
                'SELECT eal_id, eal_usr_id, eal_token FROM ' . self::LOCKS . ' WHERE eal_tracker_id = ? AND eal_event_uuid = ?',
                array($trackerId, $eventUuid)
            )->fetch();
            if ($existingLock !== false) {
                $existingToken = (string) $existingLock['eal_token'];
                if (hash_equals($sessionHash, substr($existingToken, 2, 30))) {
                    $this->database->queryPrepared(
                        'UPDATE ' . self::LOCKS . ' SET eal_expires_at = ?, eal_timestamp_change = CURRENT_TIMESTAMP WHERE eal_id = ?',
                        array($this->getExpiry($now), (int) $existingLock['eal_id'])
                    );
                    $this->database->endTransaction();

                    return array('token' => $existingToken, 'lockedUserId' => null);
                }

                $this->database->endTransaction();
                return array('token' => null, 'lockedUserId' => (int) $existingLock['eal_usr_id']);
            }

            $this->database->queryPrepared(
                'INSERT INTO ' . self::LOCKS . ' (eal_tracker_id, eal_event_uuid, eal_usr_id, eal_token, eal_expires_at) VALUES (?, ?, ?, ?, ?)',
                array($trackerId, $eventUuid, $userId, $token, $this->getExpiry($now))
            );
            $this->database->endTransaction();

            return array('token' => $token, 'lockedUserId' => null);
        } catch (\Throwable $exception) {
            $this->database->rollback();
            $existingLock = $this->database->queryPrepared(
                'SELECT eal_usr_id FROM ' . self::LOCKS . ' WHERE eal_tracker_id = ? AND eal_event_uuid = ? AND eal_expires_at > ?',
                array($trackerId, $eventUuid, $now->format('Y-m-d H:i:s'))
            )->fetch();
            if ($existingLock !== false) {
                return array('token' => null, 'lockedUserId' => (int) $existingLock['eal_usr_id']);
            }

            throw $exception;
        }
    }

    /**
     * Extend a lease owned by the submitted form token.
     */
    public function refresh(int $trackerId, string $eventUuid, int $userId, string $token): bool
    {
        if (preg_match('/^v1[a-f0-9]{62}$/', $token) !== 1) {
            return false;
        }

        $now = new DateTimeImmutable(DATETIME_NOW);
        $result = $this->database->queryPrepared(
            'UPDATE ' . self::LOCKS . ' SET eal_expires_at = ?, eal_timestamp_change = CURRENT_TIMESTAMP
              WHERE eal_tracker_id = ? AND eal_event_uuid = ? AND eal_usr_id = ? AND eal_token = ? AND eal_expires_at > ?',
            array($this->getExpiry($now), $trackerId, $eventUuid, $userId, $token, $now->format('Y-m-d H:i:s'))
        );

        if ($result->rowCount() === 1) {
            return true;
        }

        return $this->database->queryPrepared(
            'SELECT eal_id FROM ' . self::LOCKS . '
              WHERE eal_tracker_id = ? AND eal_event_uuid = ? AND eal_usr_id = ? AND eal_token = ? AND eal_expires_at > ?',
            array($trackerId, $eventUuid, $userId, $token, $now->format('Y-m-d H:i:s'))
        )->fetch() !== false;
    }

    /**
     * Release a lease after its owner has saved the attendance form.
     */
    public function release(int $trackerId, string $eventUuid, int $userId, string $token): void
    {
        if (preg_match('/^v1[a-f0-9]{62}$/', $token) !== 1) {
            return;
        }

        $this->database->queryPrepared(
            'DELETE FROM ' . self::LOCKS . ' WHERE eal_tracker_id = ? AND eal_event_uuid = ? AND eal_usr_id = ? AND eal_token = ?',
            array($trackerId, $eventUuid, $userId, $token)
        );
    }

    /**
     * Calculate the timestamp at which a newly refreshed lease expires.
     */
    private function getExpiry(DateTimeImmutable $now): string
    {
        return $now->modify('+' . $this->leaseSeconds . ' seconds')->format('Y-m-d H:i:s');
    }

    /**
     * Derive a stable, non-reversible identifier for the current session.
     */
    private function getSessionHash(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 30);
    }
}
