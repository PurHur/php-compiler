--TEST--
stdlib hash() murmur3/tiger/whirlpool/gost registry dispatch (#12903, ext/hash/hash.c)
--FILE--
<?php
$data = 'test';
echo hash('murmur3a', $data), "\n";
echo hash('murmur3c', $data), "\n";
echo hash('murmur3f', $data), "\n";
echo hash('tiger192,3', $data), "\n";
echo hash('whirlpool', $data), "\n";
echo hash('gost', $data), "\n";
--EXPECT--
ba6bd213
6f02ef30550c7d68550c7d68550c7d68
ac7d28cc74bde19d9a128231f9bd4d82
7ab383fc29d81f8d0d68e87c69bae5f1f18266d730c48b1d
b913d5bbb8e461c2c5961cbe0edcdadfd29f068225ceb37da6defcf89849368f8c6c2eb6a4c4ac75775d032a0ecfdfe8550573062b653fe92fc7b8fb3b7be8d6
a6e1acdd0cc7e00d02b90bccb2e21892289d1e93f622b8760cb0e076def1f42b
