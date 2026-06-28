<?php

declare(strict_types=1);

// Issue #9560 — %* assignment suppression must scan without capturing.
var_export(sscanf('123 456', '%*d %d'));
echo "\n";
var_export(sscanf('abc 789', '%*s %d'));
echo "\n";
