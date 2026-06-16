--TEST--
stdlib php_uname() pure path without libc FFI (#8904)
--FILE--
<?php
foreach (['a', 's', 'n', 'r', 'v', 'm'] as $mode) {
    $value = php_uname($mode);
    echo $mode, strlen($value) > 0 ? " ok\n" : " empty\n";
}
--EXPECT--
a ok
s ok
n ok
r ok
v ok
m ok
