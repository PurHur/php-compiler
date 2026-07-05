--TEST--
stdlib array_walk_recursive() closure callback with userdata (#4913)
--FILE--
<?php
$seen = [];
$nested = ['a' => [1]];
array_walk_recursive(
    $nested,
    static function (&$v, $k, $userdata) use (&$seen) {
        $seen[] = $k . ':' . $userdata;
    },
    'tag'
);
var_export($seen);
--EXPECT--
array (
  0 => '0:tag',
)
