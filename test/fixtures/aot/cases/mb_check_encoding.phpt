--TEST--
AOT: mb_check_encoding() UTF-8 validity (#4571)
--FILE--
<?php
var_dump(mb_check_encoding('café', 'UTF-8'));
var_dump(mb_check_encoding("\xFF", 'UTF-8'));
--EXPECT--
bool(true)
bool(false)
