--TEST--
stdlib array_walk() closure callback with userdata (#3627)
--FILE--
<?php
$ctx = 'seed';
array_walk([1], static function (&$v, $k, $userdata) {
    echo $userdata;
}, $ctx);
echo "\n";
$items = ['a'];
array_walk($items, static function (&$v, $k) {
    $v = strtoupper($v);
});
echo $items[0], "\n";
--EXPECT--
seed
A
