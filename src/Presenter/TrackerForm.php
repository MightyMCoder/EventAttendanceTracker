<?php
/**
 ***********************************************************************************************
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license MIT
 ***********************************************************************************************
 */
namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\UI\Presenter\FormPresenter;

final class TrackerForm extends FormPresenter
{
    /**
     * Set the property of a form element before validating a submitted tracker form.
     * @throws Exception
     */
    public function setElementProperty(string $id, int $property): void
    {
        if (!array_key_exists($id, $this->elements)) {
            throw new Exception('Invalid form element!');
        }

        $this->elements[$id]['property'] = $property;
    }
}
