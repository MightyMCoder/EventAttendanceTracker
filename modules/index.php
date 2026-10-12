<?php

declare(strict_types=1);

// Compatibility endpoint for menu entries created before the root entry point.
header('Location: ../event-attendance-tracker.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
exit;
