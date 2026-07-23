<?php
// #22473 — DateInterval var_dump / print_r / debug_zval_dump Zend DEBUG wire
$i = new DateInterval('P1DT2H');
ob_start();
var_dump($i);
$vd = (string) ob_get_clean();
echo str_contains($vd, 'object(DateInterval)') ? "vd_ok\n" : "vd_bad\n";
echo str_contains($vd, '["y"]') ? "vd_has_y\n" : "vd_no_y\n";
echo str_contains($vd, 'date_string') ? "vd_has_date_string\n" : "vd_no_date_string\n";

ob_start();
print_r($i);
$pr = (string) ob_get_clean();
echo str_starts_with($pr, 'DateInterval Object') ? "pr_ok\n" : "pr_bad\n";

ob_start();
debug_zval_dump($i);
$dz = (string) ob_get_clean();
echo str_contains($dz, 'object(DateInterval)') ? "dz_ok\n" : "dz_bad\n";

$f = DateInterval::createFromDateString('1 day');
ob_start();
var_dump($f);
$fd = (string) ob_get_clean();
echo str_contains($fd, '["from_string"]') && str_contains($fd, '["date_string"]')
    ? "from_ok\n"
    : "from_bad\n";
