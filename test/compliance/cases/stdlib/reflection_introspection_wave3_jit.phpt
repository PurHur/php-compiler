--TEST--
Stdlib: reflection wave 3 — property_exists, kind_exists (JIT, #1370–#1373)
--FILE--
<?php
class Box {
    public $a = 1;
}
echo property_exists('Box', 'a') ? '1' : '0';
echo property_exists('Box', 'missing') ? '1' : '0';
echo trait_exists('Box') ? '1' : '0';
echo interface_exists('Box') ? '1' : '0';
echo enum_exists('Box') ? '1' : '0';
echo class_exists('Box') ? '1' : '0';
echo "\n";
--EXPECT--
100001
