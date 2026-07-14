--TEST--
AOT: stripcslashes()/strtolower() null $string TypeError under declare(strict_types=1) (#18780, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

stripcslashes(null);
--EXPECT--
--EXPECT_EXIT--
134
