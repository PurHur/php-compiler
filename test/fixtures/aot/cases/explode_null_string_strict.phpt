--TEST--
AOT: explode() null $string TypeError under declare(strict_types=1) (#18600, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

explode(',', null);
--EXPECT--
--EXPECT_EXIT--
134
