<?php

declare(strict_types=1);

// #23978 AOT-safe probe — IEEE bits via pack (var_export/json_encode float SEGV under AOT).
$pos = abs(-0.0);
$neg = -0.0;
echo bin2hex(pack('d', $pos)), "\n";
echo bin2hex(pack('d', $neg)), "\n";
