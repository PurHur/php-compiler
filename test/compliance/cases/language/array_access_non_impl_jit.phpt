--TEST--
Language: ArrayAccess — non-ArrayAccess object keeps Illegal offset error (JIT, #4012)
--FILE--
<?php
class Plain {}
$p = new Plain();
$p['nope'] = 1;
--EXPECT_EXIT--
255
