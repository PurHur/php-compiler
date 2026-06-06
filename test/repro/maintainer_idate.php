<?php

declare(strict_types=1);

$ts = 946684800;
echo 'Y=' . idate('Y', $ts) . "\n";
echo 'm=' . idate('m', $ts) . "\n";
echo 'd=' . idate('d', $ts) . "\n";
echo 'w=' . idate('w', $ts) . "\n";
echo 'U=' . idate('U', $ts) . "\n";
var_export(idate('YY', $ts));
echo "\n";
var_export(idate('?', $ts));
echo "\n";
