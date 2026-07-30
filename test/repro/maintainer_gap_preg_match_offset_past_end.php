<?php
$m = null;
$r = preg_match('/a/', 'bbb', $m, PREG_OFFSET_CAPTURE, 10);
echo 'match r=', var_export($r, true), ' type=', get_debug_type($m), ' empty=', (int) (is_array($m) && $m === []), ' err=', preg_last_error(), "\n";

$m = null;
$r = preg_match_all('/a/', 'bbb', $m, PREG_OFFSET_CAPTURE, 10);
echo 'all r=', var_export($r, true), ' type=', get_debug_type($m), ' empty=', (int) (is_array($m) && $m === []), ' err=', preg_last_error(), "\n";

$m = null;
$r = preg_match('/a/', 'bbb', $m, PREG_OFFSET_CAPTURE, 3);
echo 'eqlen r=', var_export($r, true), ' type=', get_debug_type($m), ' empty=', (int) (is_array($m) && $m === []), "\n";

$m = ['keep'];
@$r = preg_match('/[/', 'bbb', $m, PREG_OFFSET_CAPTURE);
echo 'badpat kept=', var_export($m, true), "\n";
