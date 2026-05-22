--TEST--
stdlib stristr()
--FILE--
<?php
echo stristr("Hello World", "lo"), "\n";
echo stristr("ABC-DEF", "-", true), "\n";
echo stristr("no match", "zzz") === false ? "false\n" : "bad\n";
--EXPECT--
lo World
ABC
false
