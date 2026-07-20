--TEST--
igbinary stdClass round-trip + Zend fixture (#21463)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!function_exists('igbinary_serialize')) die('skip no igbinary_serialize');
?>
--FILE--
<?php
$o = new stdClass();
$o->a = 1;
$o->b = 'x';
$u = igbinary_unserialize(igbinary_serialize($o));
echo ($u instanceof stdClass) ? 'Y' : 'N', "\n";
echo 'a=', $u->a, ' b=', $u->b, "\n";

$fixture = hex2bin('000000021708737464436c61737314011101610601');
$z = igbinary_unserialize($fixture);
echo ($z instanceof stdClass && 1 === $z->a) ? 'fixture=Y' : 'fixture=N', "\n";

$data = ['k' => 1, 'nested' => [true, 'x']];
echo igbinary_unserialize(igbinary_serialize($data)) === $data ? "scalar_ok\n" : "scalar_fail\n";
?>
--EXPECT--
Y
a=1 b=x
fixture=Y
scalar_ok
