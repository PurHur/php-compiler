--TEST--
language WeakMap — foreach yields object keys on VM and JIT (#4434)
--FILE--
<?php
$m = new WeakMap();
$o = new stdClass();
$m[$o] = 'v';

echo ($m->count() === 1 ? 'count_ok' : 'count_bad'), "\n";
echo ($m[$o] === 'v' ? 'get_ok' : 'get_bad'), "\n";

foreach ($m as $k => $v) {
    echo (is_object($k) && $k instanceof stdClass ? 'key_ok' : 'key_bad'), "\n";
    echo ($v === 'v' ? 'iter_ok' : 'iter_bad'), "\n";
}
--EXPECT--
count_ok
get_ok
key_ok
iter_ok
