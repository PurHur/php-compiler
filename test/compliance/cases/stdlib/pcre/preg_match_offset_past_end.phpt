--TEST--
stdlib preg_match/_all past-end offset binds empty $matches (#25313, php-src-strict)
--FILE--
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
?>
--EXPECT--
match r=false type=array empty=1 err=1
all r=false type=array empty=1 err=1
eqlen r=0 type=array empty=1
badpat kept=array (
  0 => 'keep',
)
