--TEST--
Language: boxed float === / match vs float literal (#35201)
--FILE--
<?php
$x = 1.5;
var_dump($x === 1.5);
var_dump($x !== 1.5);
var_dump($x == 1.5);
var_dump($x === 1);
$a = 1.5;
$b = 1.5;
var_dump($a === $b);
echo match ($x) {
    1.5 => "ok",
    default => "no",
}, "\n";
var_dump(NAN === NAN);
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
ok
bool(false)
