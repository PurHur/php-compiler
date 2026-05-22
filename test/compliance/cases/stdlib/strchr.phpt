--TEST--
stdlib strchr() alias of strstr()
--FILE--
<?php
echo strchr("hello world", "lo"), "\n";
--EXPECT--
lo world
