<?php
/**
 * Verifies that tracker forms survive session restoration before plugins are loaded.
 *
 * @copyright MightyMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Tests\Unit;

use Admidio\UI\Presenter\FormPresenter;
use PHPUnit\Framework\TestCase;

final class TrackerFormSessionTest extends TestCase
{
    /** Restore and adjust a form in an isolated request using only the core autoloader. */
    public function testNativeFormCanBeRestoredWithoutPluginAutoloading(): void
    {
        $reflection = new \ReflectionClass(FormPresenter::class);
        $form = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('showRequiredFields')->setValue($form, true);
        $reflection->getProperty('elements')->setValue($form, array(
            'tracker_calendar' => array('property' => FormPresenter::FIELD_REQUIRED)
        ));
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $script = 'require ' . var_export($autoload, true) . ';
            $form = unserialize(stream_get_contents(STDIN));
            $element = $form->getElement("tracker_calendar");
            $element["property"] = \\Admidio\\UI\\Presenter\\FormPresenter::FIELD_DISABLED;
            $form->replaceElement("tracker_calendar", $element);
            echo json_encode(array(get_class($form), $form->getElement("tracker_calendar")["property"]));';
        $process = proc_open(array(PHP_BINARY, '-r', $script), array(
            0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')
        ), $pipes);
        $this->assertIsResource($process);
        fwrite($pipes[0], serialize($form));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        $this->assertSame(array(FormPresenter::class, FormPresenter::FIELD_DISABLED), json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }
}
