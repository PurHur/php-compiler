--TEST--
stdlib strrev() JIT
--FILE--
<?php
echo strrev('hello'), "\n";
--EXPECT--
olleh
