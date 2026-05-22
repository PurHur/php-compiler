--TEST--
stdlib strstr()
--FILE--
<?php
echo strstr("hello world", "lo"), "\n";
echo strstr("abc-def", "-", true), "\n";
--EXPECT--
lo world
abc