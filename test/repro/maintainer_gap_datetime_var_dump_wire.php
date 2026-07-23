<?php
declare(strict_types=1);

ob_start();
var_dump(new DateTime('2020-01-01'));
$out = ob_get_clean();
echo str_contains($out, '__dt_') ? "LEAK\n" : "WIRE\n";
echo str_contains($out, '["date"]') || str_contains($out, '[date]') ? "has_date\n" : "no_date\n";
