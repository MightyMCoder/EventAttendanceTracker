<?php
/**
 * Contains the tracker form needed to adapt dynamic source fields before validation.
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\UI\Presenter\FormPresenter;

/**
 * Exposes the one FormPresenter operation needed for mutually exclusive tracker sources.
 */
final class TrackerForm extends FormPresenter
{
    /**
     * Change the property of a form element before submitted values are validated.
     *
     * @param string $id       The identifier of the form element.
     * @param int    $property The FormPresenter field property to apply.
     * @return void
     * @throws Exception
     */
    public function setElementProperty(string $id, int $property): void
    {
        if (!array_key_exists($id, $this->elements)) {
            throw new Exception('Invalid tracker form element.');
        }

        $this->elements[$id]['property'] = $property;
    }
}
