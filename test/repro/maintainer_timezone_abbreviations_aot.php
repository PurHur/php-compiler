<?php

declare(strict_types=1);

/**
 * Issue #16866 — TimezoneAbbreviationsData AOT compile + count parity.
 */
$tz = require __DIR__.'/../../ext/standard/TimezoneAbbreviationsData.php';
echo count($tz), "\n";
