--TEST--
Global const and fetch (issue #204)
--FILE--
<?php
const X = 42;
echo X;
--EXPECT--
42
