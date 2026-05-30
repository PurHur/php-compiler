--TEST--
sealed class permits: only listed subclasses may extend (#3322)
--FILE--
<?php
sealed class C permits D, E {}
class D extends C {}
class X extends C {}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTREGEX--
not in the list of allowed subclasses
