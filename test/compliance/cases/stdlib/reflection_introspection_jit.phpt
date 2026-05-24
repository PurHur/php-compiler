--TEST--
Stdlib: reflection / introspection builtins (JIT, #1214–#1219)
--FILE--
<?php
class Box {
    public $size = 1;
    public static $kind = 'box';
    public function ping() {}
}
$o = new Box();
echo class_exists('Box') ? '1' : '0';
echo class_exists('Missing') ? '1' : '0';
echo method_exists('Box', 'ping') ? '1' : '0';
echo method_exists('Box', 'pong') ? '1' : '0';
echo property_exists('Box', 'size') ? '1' : '0';
echo property_exists('Box', 'kind') ? '1' : '0';
echo property_exists('Box', 'missing') ? '1' : '0';
echo property_exists('Missing', 'size') ? '1' : '0';
echo get_class($o) === 'Box' ? '1' : '0';
echo is_a($o, 'Box') ? '1' : '0';
echo is_a($o, 'Other') ? '1' : '0';
echo "\n";
--EXPECT--
10101110110
