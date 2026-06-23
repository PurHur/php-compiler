--TEST--
stdlib intval()/floatval() array/object/resource coercion (#10810, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);

echo 'intval([]): ', intval([]), "\n";
echo 'floatval([]): ', floatval([]), "\n";
echo 'intval([1]): ', intval([1]), "\n";
echo 'floatval([1]): ', floatval([1]), "\n";
echo 'intval(obj): ', @intval(new stdClass()), "\n";
@intval(new stdClass());
$err = error_get_last();
echo 'warning: ', $err['message'] ?? '', "\n";
$r = fopen('php://memory', 'r');
$resId = @intval($r);
echo 'intval(res): ', $resId > 0 ? 'positive' : 'zero', "\n";
?>
--EXPECT--
intval([]): 0
floatval([]): 0
intval([1]): 1
floatval([1]): 1
intval(obj): 1
warning: Object of class stdClass could not be converted to int
intval(res): positive
