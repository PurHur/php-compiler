--TEST--
Stdlib: assert_options() JIT/AOT — assert.active get/set (#3316)
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
