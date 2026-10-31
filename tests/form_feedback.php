<?php
/** Run with: php tests/form_feedback.php (requires the surrounding Admidio installation). */

use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\AttendancePresenter;
use AdmidioPlugin\EventAttendance\Presenter\EventAttendancePagePresenter;
use AdmidioPlugin\EventAttendance\Presenter\LegacySettingsPresenter;

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$response = static function (?string $url = null, ?string $message = null): string {
    ob_start();
    EventAttendancePagePresenter::sendJsonSuccess($url, $message);
    return ob_get_clean();
};
$saved = $response('/configurations', 'Data has been saved.');
$check(json_decode($saved, true, 512, JSON_THROW_ON_ERROR) === array(
    'status' => 'success', 'url' => '/configurations', 'message' => 'Data has been saved.'
), 'Save response must include the standard message and preserve its return URL.');
$check($response() === '{"status":"success"}', 'Background actions must remain silent.');
$check(json_decode($response('/attendance'), true, 512, JSON_THROW_ON_ERROR) === array('status' => 'success', 'url' => '/attendance'), 'Acquiring a lock must not show a save message.');

// Test the real settings save-button factory and the template's dynamic lookup.
$gL10n = new class {
    public function get(string $id): string { return $id; }
};
$gCurrentSession = new class {
    public array $forms = array();
    public function addFormObject(FormPresenter $form): void { $this->forms[] = $form; }
};
$smarty = new Smarty\Smarty();
$smarty->setTemplateDir(dirname(__DIR__, 3) . '/themes/simple/templates');
$compileDir = sys_get_temp_dir() . '/event-attendance-feedback-' . bin2hex(random_bytes(6));
mkdir($compileDir);
$smarty->setCompileDir($compileDir);
$finish = new ReflectionMethod(LegacySettingsPresenter::class, 'finish');
$forms = '';
$buttonIds = array();
foreach (array('access', 'automation', 'rules', 'locks', 'export') as $panel) {
    $id = 'adm_event_attendance_settings_' . $panel;
    $form = new FormPresenter($id, '', '/save_settings?panel=' . $panel, null, array('setFocus' => false));
    $finish->invoke(null, $form);
    $template = file_get_contents(dirname(__DIR__) . '/templates/plugin.event-attendance.settings.' . $panel . '.tpl');
    $check(str_contains($template, 'class="form-alert"'), 'Missing settings feedback area: ' . $panel);
    preg_match('/\{include \'sys-template-parts\/form.button.tpl\'[^\n]+/', $template, $match);
    $smarty->assign('attributes', $form->getAttributes());
    $smarty->assign('elements', $form->getElements());
    $button = $smarty->fetch('string:' . $match[0]);
    $check(str_contains($button, 'id="' . $id . '_save"'), 'Rendered button must have a form-specific ID: ' . $panel);
    $buttonIds[] = $id . '_save';
    $forms .= '<form id="' . $id . '" action="/save_settings?panel=' . $panel . '"><input name="value" value="unsaved"><div class="form-alert" style="display:none"></div>' . $button . '</form>';
}
$check(count(array_unique($buttonIds)) === 5, 'Submit button IDs must be unique.');
$check(count($gCurrentSession->forms) === 5, 'All settings forms must remain registered for validation.');
foreach (glob(dirname(__DIR__) . '/templates/*.tpl') as $template) {
    $smarty->createTemplate($template)->compileTemplateSource();
}
$page = new class extends PagePresenter {
    public array $scripts = array();
    public function __construct() {}
    public function addJavascript(string $javascriptCode, bool $executeAfterPageLoad = false): void { $this->scripts[] = $javascriptCode; }
    public function assignSmartyVariable(string $variable, mixed $value): void {}
    public function addHtmlByTemplate(string $template): void {}
};
(new AttendancePresenter())->create($page, '/save_attendance', array(), 'test-token', '/heartbeat', 1, '/overview');
if (($argv[1] ?? '') === 'fixtures') {
    echo json_encode(array('forms' => $forms, 'saved' => $saved, 'attendanceScripts' => $page->scripts), JSON_THROW_ON_ERROR);
} else {
    echo "Save responses, settings buttons and all Smarty templates passed.\n";
}
