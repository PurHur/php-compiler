--TEST--
AOT: array callable ['Class','method']() after closure definition (#33800)
--FILE--
<?php
class SC { public static $v = 12; }
$f = static function (): int { return SC::$v; };
class AC { public static function m(): int { return 42; } }
$cb = [AC::class, 'm'];
echo $f(), "\n";
echo $cb(), "\n";
--EXPECT--
12
42
