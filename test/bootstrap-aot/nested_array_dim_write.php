<?php

declare(strict_types=1);

/**
 * Chained string-key dim assignment on nested arrays (issues #827, #1072).
 */

$a = ['outer' => ['inner' => 42]];
$a['outer']['inner'] = 99;
echo $a['outer']['inner'], "\n";

$b = ['layer' => ['name' => 'old', 'active' => false]];
$b['layer']['name'] = 'new';
$b['layer']['active'] = true;
echo $b['layer']['name'], "\n";
echo $b['layer']['active'] ? '1' : '0';
echo "\n";
