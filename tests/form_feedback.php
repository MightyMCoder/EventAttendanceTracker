<?php
/**
 * Builds fixtures from the native attendance presenter for form-feedback regression tests.
 *
 * Run with: php tests/form_feedback.php | NODE_PATH=/path/to/jsdom/node_modules node tests/form_feedback.cjs
 *
 * @copyright MighryMCoder
 * @see https://github.com/MightyMCoder/EventAttendanceTracker
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\AttendanceFormPresenter;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Presenter/AttendanceFormPresenter.php';

$gL10n = new class {
    /** Keep translation dependencies out of the generated lock-handler fixture. */
    public function get(string $id): string
    {
        return $id;
    }
};
$gCurrentSession = new class {
    /** Accept the real form without creating a database-backed session. */
    public function addFormObject(FormPresenter $form): void
    {
    }
};
$page = new class extends PagePresenter {
    public array $scripts = array();

    /** Capture presenter output without bootstrapping an entire HTTP request. */
    public function __construct()
    {
    }

    /** Collect the actual production JavaScript for the browser regression test. */
    public function addJavascript(string $javascriptCode, bool $executeAfterPageLoad = false): void
    {
        $this->scripts[] = $javascriptCode;
    }

    /** The fixture only needs JavaScript; templates are checked separately. */
    public function assignSmartyVariable(string $variable, mixed $value): void
    {
    }

    /** Do not render a page in this database-free fixture generator. */
    public function addHtmlByTemplate(string $template): void
    {
    }
};
(new AttendanceFormPresenter())->create($page, '/save_attendance', array(), 'test-token', '/heartbeat', 1, '/overview');
echo json_encode(array(
    'saved' => json_encode(array('status' => 'success', 'url' => '/configurations', 'message' => 'Data has been saved.'), JSON_THROW_ON_ERROR),
    'attendanceScripts' => $page->scripts
), JSON_THROW_ON_ERROR);
