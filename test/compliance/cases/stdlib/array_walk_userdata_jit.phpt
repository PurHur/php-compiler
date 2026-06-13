--TEST--
stdlib array_walk() closure callback with userdata JIT/AOT (#4916)
--FILE--
<?php
$items = [1];
$ok = array_walk(
    $items,
    static function (&$v, $k, $userdata) {
        echo $k, ':', $userdata, "\n";
    },
    'u'
);
echo $ok ? "ok\n" : "fail\n";
--EXPECT--
0:u
ok
