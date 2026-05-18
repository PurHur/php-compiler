--TEST--
AOT: getenv() returns false for missing key
--FILE--
<?php
echo getenv('APP_DEBUG_NONEXISTENT') === false ? "false\n" : "set\n";
--EXPECT--
false
