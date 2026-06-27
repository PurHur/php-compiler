--TEST--
Language: keyed list destructuring to non-writable literal — compile-time fatal (#12498)
--FILE--
<?php
declare(strict_types=1);
['a' => 1] = ['a' => 1];
echo "ran\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Assignments can only happen to writable values in %s on line %d
