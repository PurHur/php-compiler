--TEST--
Language: instanceof for user-defined classes (JIT, #138)
--FILE--
<?php
class Box {}
class Other {}
$o = new Box();
echo ($o instanceof Box) ? '1' : '0';
echo ($o instanceof Other) ? '1' : '0';
echo (null instanceof Box) ? '1' : '0';
--EXPECT--
100
