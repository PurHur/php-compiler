<?php

declare(strict_types=1);

$path = '/nope/'.getmypid();
foreach (['fileatime', 'filectime', 'fileinode', 'fileowner', 'filegroup', 'fileperms'] as $fn) {
    var_export($fn($path));
    echo "\n";
}
