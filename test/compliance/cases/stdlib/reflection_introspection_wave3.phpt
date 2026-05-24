--TEST--
Stdlib: reflection wave 3 — property_exists, kind_exists, get_object_vars (VM, #1370–#1373)
--FILE--
<?php
class Box {
    public $a = 1;
    public $b = 'two';
}
$o = new Box();
$vars = get_object_vars($o);
echo count($vars);
echo isset($vars['a']) && $vars['a'] === 1 ? '1' : '0';
echo isset($vars['b']) && $vars['b'] === 'two' ? '1' : '0';
echo property_exists($o, 'a') ? '1' : '0';
echo property_exists('Box', 'a') ? '1' : '0';
echo property_exists('Box', 'missing') ? '1' : '0';
echo trait_exists('Box') ? '1' : '0';
echo interface_exists('Box') ? '1' : '0';
echo enum_exists('Box') ? '1' : '0';
echo class_exists('Box') ? '1' : '0';
echo "\n";
--EXPECT--
2111100001
