<?php
/** Run with: php tests/tracker_form_session.php (requires the surrounding Admidio installation). */

use Admidio\UI\Presenter\FormPresenter;
use AdmidioPlugin\EventAttendance\Presenter\TrackerForm;

$pluginRoot = dirname(__DIR__);
$coreAutoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

if (isset($argv[1])) {
    if ($argv[1] === 'early') {
        require $pluginRoot . '/autoload.php';
    }
    require $coreAutoload;
    $form = unserialize(stream_get_contents(STDIN));
    if ($argv[1] === 'late') {
        require $pluginRoot . '/autoload.php';
    }
    if ($form instanceof TrackerForm) {
        $form->setElementProperty('tracker_calendar', FormPresenter::FIELD_DISABLED);
        if ($form->getElements()['tracker_calendar']['property'] !== FormPresenter::FIELD_DISABLED) {
            throw new RuntimeException('Restored form cannot be adjusted before validation.');
        }
    }
    echo get_class($form) . "\n" . base64_encode(serialize($form));
    exit;
}

require $pluginRoot . '/autoload.php';
require $coreAutoload;
$reflection = new ReflectionClass(TrackerForm::class);
$form = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('showRequiredFields')->setValue($form, true);
$reflection->getProperty('elements')->setValue($form, array('tracker_calendar' => array('property' => FormPresenter::FIELD_REQUIRED)));

$restore = static function (string $mode, string $serialized): array {
    $process = proc_open(array(PHP_BINARY, __FILE__, $mode), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start isolated PHP request.');
    }
    fwrite($pipes[0], $serialized);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException($error);
    }
    return explode("\n", $output, 2);
};

$serialized = serialize($form);
[$lateClass, $lateSerialized] = $restore('late', $serialized);
if ($lateClass !== '__PHP_Incomplete_Class') {
    throw new RuntimeException('Original loading-order problem was not reproduced.');
}
foreach (array($serialized, base64_decode($lateSerialized, true)) as $sessionForm) {
    [$earlyClass] = $restore('early', $sessionForm);
    if ($earlyClass !== TrackerForm::class) {
        throw new RuntimeException('Early autoloading did not restore the session form.');
    }
}
echo "Tracker form restored successfully across isolated PHP requests.\n";
