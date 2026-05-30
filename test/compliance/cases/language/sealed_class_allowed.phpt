--TEST--
sealed class permits: permitted subclass extends successfully (#3322)
--FILE--
<?php
sealed class C permits D {}
class D extends C {}
echo "ok\n";
--EXPECT--
ok
