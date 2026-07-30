<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
foreach (['2020W03', '2020-W03-1', '2020W01', '2020-W01-1', '2020013', '2020-013', '2020365', '2020-365', '2020001'] as $s) {
    $r = @strtotime($s);
    echo $s, ' => ', var_export($r, true);
    if (is_int($r)) {
        echo ' ', date('Y-m-d', $r);
    }
    echo "\n";
}
