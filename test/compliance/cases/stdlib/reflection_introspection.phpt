--TEST--
Stdlib: reflection / introspection builtins (VM, #1214–#1219)
--FILE--
<?php
class Box {
    public function ping() {}
}
$o = new Box();
echo class_exists('Box') ? '1' : '0';
echo class_exists('Missing') ? '1' : '0';
echo method_exists('Box', 'ping') ? '1' : '0';
echo method_exists('Box', 'pong') ? '1' : '0';
echo get_class($o) === 'Box' ? '1' : '0';
echo is_a($o, 'Box') ? '1' : '0';
echo is_a($o, 'Other') ? '1' : '0';
echo "\n";
--EXPECT--
1010110
