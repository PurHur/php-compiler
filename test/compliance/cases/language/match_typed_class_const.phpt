--TEST--
Language: match in typed class constant initializer (#9987)
--FILE--
<?php
class C {
    public const int X = match (2) { 1 => 10, 2 => 20, default => 0 };
}
var_export(C::X);
echo "\n";

interface I {
    public const string TAG = match ('a') { 'a' => 'alpha', default => 'other' };
}
var_export(I::TAG);
echo "\n";
--EXPECT--
20
'alpha'
