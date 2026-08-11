--TEST--
Language: $a[missing]++ emits Undefined array key Warning then stores 1 (#30078, zend_vm_def.h)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$x = [];
$x[0]++;
echo "done\n";
var_export($x);
echo "\n";

$f = false;
$f[0]++;
var_export($f);
echo "\n";
--EXPECT--
W:Undefined array key 0
done
array (
  0 => 1,
)
W:Automatic conversion of false to array is deprecated
W:Undefined array key 0
array (
  0 => 1,
)
