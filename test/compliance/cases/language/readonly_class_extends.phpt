--TEST--
Language: readonly class inheritance — valid readonly parent chain (#8967)
--FILE--
<?php
readonly class A {}
readonly class R extends A {}
echo "ok\n";
--EXPECT--
ok
