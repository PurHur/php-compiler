--TEST--
stdlib wddx_packet_start/add_vars/packet_end round-trip (#27858, pecl-text-wddx wddx.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$a = 1;
$b = 'two';
$viaSerialize = wddx_deserialize(wddx_serialize_vars('a', 'b'));

$packet = wddx_packet_start();
echo is_resource($packet) ? '1' : '0';
echo wddx_add_vars($packet, 'a', 'b') ? '1' : '0';
$xml = wddx_packet_end($packet);
echo is_string($xml) ? '1' : '0';
echo is_resource($packet) ? '0' : '1';
$viaPacket = wddx_deserialize($xml);
echo is_array($viaPacket) && $viaPacket === $viaSerialize ? '1' : '0';
echo function_exists('wddx_packet_start') ? '1' : '0';
echo function_exists('wddx_add_vars') ? '1' : '0';
echo function_exists('wddx_packet_end') ? '1' : '0';
echo "\n";
--EXPECT--
11111111
