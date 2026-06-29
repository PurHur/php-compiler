--TEST--
stdlib hash() legacy registry digests ripemd/snefru/haval/joaat (#13629, ext/hash/hash.c)
--FILE--
<?php
$data = 'test';
echo hash('ripemd160', $data), "\n";
echo hash('snefru', $data), "\n";
echo hash('haval128,3', $data), "\n";
echo hash('joaat', $data), "\n";
--EXPECT--
5e52fee47e6b070565f74372468cdc699de89107
8d25dd0b5715f7e4c799ade3a34b5f6148d0ce416992b5c2eaf614d35d5b3d30
a26075021e24a5bda74794d85e9fdb7f
3f75ccc1
