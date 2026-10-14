<?php
/**
 * Contains the responder that sends JSON responses for plugin actions.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */


namespace AdmidioPlugin\EventAttendance\Presenter;

use JsonException;

/** Creates the JSON payloads consumed by Admidio form and Ajax requests. */
final class JsonResponsePresenter
{
    /** @param array<string,mixed> $payload */
    public static function send(array $payload): void
    {
        echo json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /** @throws JsonException */
    public static function success(?string $url = null): void
    {
        $payload = array('status' => 'success');
        if ($url !== null) {
            $payload['url'] = $url;
        }

        self::send($payload);
    }

}
