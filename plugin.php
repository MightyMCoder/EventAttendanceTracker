<?php
/**
 * Event attendance - local planning scaffold.
 */

use AdmidioPlugin\EventAttendance\EventAttendance;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit('This page may not be called directly!');
}

EventAttendance::register();
