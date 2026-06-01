--TEST--
Language: dynamic class constant fetch Class::{$name} JIT (#3150)
--FILE--
<?php
class C {
    public const X = 42;
    public const LABEL = 'ok';
}
$n = 'X';
echo C::{$n};
echo "\n";
$m = 'label';
echo C::{$m};
echo "\n";
--EXPECT--
42
ok
