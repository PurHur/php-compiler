--TEST--
stdlib strrchr()
--FILE--
<?php
echo strrchr("hello.world", "."), "\n";
echo strrchr("no-match", "z") === false ? "0\n" : "1\n";
--EXPECT--
.world
0
