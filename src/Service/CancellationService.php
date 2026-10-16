<?php
/**
 * Contains cancellation handling for event-attendance occurrences.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Service;

use Admidio\Events\Entity\Event;
use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use AdmidioPlugin\EventAttendance\EventAttendance;

final class CancellationService
{
    private const EVENTS = TABLE_PREFIX . '_event_attendance_events';
    private const TRACKER_EVENTS = TABLE_PREFIX . '_event_attendance_tracker_events';

    public function __construct(private readonly Database $database)
    {
    }

    public function cancel(int $trackerId, string $eventUuid, int $actorId): void
    {
        $event = $this->database->queryPrepared(
            'SELECT dat_id, dat_uuid
               FROM ' . TABLE_PREFIX . '_event_attendance_trackers
         LEFT JOIN ' . self::TRACKER_EVENTS . ' ON ete_tracker_id = eat_id
              INNER JOIN ' . TBL_EVENTS . ' ON dat_id = ete_dat_id OR (dat_cat_id = eat_cat_id AND dat_begin >= eat_source_start)
              WHERE eat_id = ? AND dat_uuid = ?',
            [$trackerId, $eventUuid]
        )->fetch();
        if ($event === false) {
            throw new \InvalidArgumentException('Event is not configured for this tracker.');
        }

        $this->database->startTransaction();
        try {
            if ($this->syncCoreCancellationEnabled()) {
                $this->cancelCoreEvent((string) $event['dat_uuid']);
            }

            $row = $this->database->queryPrepared(
                'SELECT eae_id FROM ' . self::EVENTS . ' WHERE eae_tracker_id = ? AND eae_dat_id = ?',
                [$trackerId, (int) $event['dat_id']]
            )->fetch();
            if ($row === false) {
                $this->database->queryPrepared(
                    'INSERT INTO ' . self::EVENTS . ' (eae_tracker_id, eae_dat_id) VALUES (?, ?)',
                    [$trackerId, (int) $event['dat_id']]
                );
                $trackerEventId = (int) $this->database->lastInsertId();
            } else {
                $trackerEventId = (int) $row['eae_id'];
            }
            $this->database->queryPrepared(
                'UPDATE ' . self::EVENTS . ' SET eae_cancelled = true, eae_cancelled_by_usr_id = ?, eae_cancelled_at = CURRENT_TIMESTAMP WHERE eae_id = ?',
                [$actorId, $trackerEventId]
            );
            $this->database->endTransaction();
        } catch (\Throwable $exception) {
            $this->database->rollback();
            throw $exception;
        }
    }

    private function syncCoreCancellationEnabled(): bool
    {
        $plugin = PluginRegistry::requireEnabled(EventAttendance::PLUGIN_ID);

        return (bool) $plugin->getSettingValues()['event_attendance_sync_core_cancellation'];
    }

    private function cancelCoreEvent(string $eventUuid): void
    {
        $event = new Event($this->database);
        if (!$event->readDataByUuid($eventUuid)) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        if (!$event->isEditable()) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        // Do not call EventService::deleteData() here: it physically deletes a non-recurring
        // event and cascades into the plugin's immutable attendance history. A cancelled
        // recurrence status is honoured by Admidio's event listings while preserving that data.
        $event->setValue('dat_recurrence_status', 'cancelled');
        $event->setValue('dat_recurrence_scope', 'this');
        $event->save();
    }
}
