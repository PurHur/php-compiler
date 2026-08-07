--TEST--
stdlib msgpack_serialize/unserialize + MessagePack OO (#27872, PECL msgpack/msgpack-php)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsMsgpack()) {
    die('skip msgpack withheld on reference profile (#17994)');
}
--FILE--
<?php
declare(strict_types=1);

$data = ['a' => 1, 'b' => [2, 3], 'c' => 'hello'];
$pack = msgpack_pack($data);
$ser = msgpack_serialize($data);
$mp = new MessagePack();
$oo = $mp->pack($data);
echo function_exists('msgpack_serialize') ? '1' : '0';
echo function_exists('msgpack_unserialize') ? '1' : '0';
echo class_exists('MessagePack', false) ? '1' : '0';
echo defined('MESSAGEPACK_OPT_PHPONLY') && MESSAGEPACK_OPT_PHPONLY === -1001 ? '1' : '0';
echo ($pack === $ser && $pack === $oo) ? '1' : '0';
echo (msgpack_unserialize($ser) === $data && $mp->unpack($pack) === $data) ? '1' : '0';
echo "\n";
?>
--EXPECT--
111111
