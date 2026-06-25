<?php

declare(strict_types=1);

echo ini_get('precision') === '14' ? "default-ok\n" : "default-bad\n";
$old = ini_set('precision', '8');
echo $old === '14' ? "set-old-ok\n" : "set-old-bad\n";
echo ini_get('precision') === '8' ? "set-ok\n" : "set-bad\n";
ini_restore('precision');
echo ini_get('precision') === '14' ? "restore-ok\n" : "restore-bad\n";
