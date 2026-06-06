--TEST--
Language: standalone never parameter type compiles (#6633)
--FILE--
<?php
function acceptsNever(never $value): void {}
echo "ok\n";
--EXPECT--
ok
