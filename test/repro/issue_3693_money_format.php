<?php
declare(strict_types=1);
setlocale(LC_MONETARY, 'C');
echo function_exists('money_format') ? "exists\n" : "missing\n";
$ok = money_format('%i', 1234.56);
echo 'fmt=', var_export($ok, true), "\n";
$bad = money_format('%^', 1.0);
echo 'bad=', var_export($bad, true), "\n";
