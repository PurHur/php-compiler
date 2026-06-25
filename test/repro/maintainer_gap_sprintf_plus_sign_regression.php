<?php
declare(strict_types=1);

$int = sprintf('%+d', 5);
$float = sprintf('%+.2f', 1.5);

$ok = $int === '+5' && $float === '+1.50';
echo $ok ? "ok\n" : "fail int={$int} float={$float}\n";
