--TEST--
Stdlib: assert.active=0 makes assert(false) a no-op (#3316)
--INI--
zend.assertions=1
--FILE--
<?php
echo assert_options(ASSERT_ACTIVE), "\n";
assert_options(ASSERT_ACTIVE, 0);
assert(false);
echo "ok\n";
--EXPECT--
1
ok
