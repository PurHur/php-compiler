--TEST--
Stdlib: unset() dynamic property — property_exists false (JIT, #15750)
--FILE--
<?php
class UserDyn {
}
$o = new stdClass();
$o->x = 1;
unset($o->x);
$c = new UserDyn();
$c->dyn = 1;
unset($c->dyn);
echo property_exists($o, 'x') ? '1' : '0';
echo property_exists($c, 'dyn') ? '1' : '0';
echo isset($o->x) ? '1' : '0';
echo isset($c->dyn) ? '1' : '0';
echo array_key_exists('x', get_object_vars($o)) ? '1' : '0';
echo array_key_exists('dyn', get_object_vars($c)) ? '1' : '0';
echo "\n";
--EXPECT--
000000
