--TEST--
stdlib stripos() case-insensitive match (#14000)
--FILE--
<?php
var_dump(stripos('Hello World', 'world'));
--EXPECT--
int(6)
