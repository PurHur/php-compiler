--TEST--
AOT: htmlspecialchars_decode() null $string TypeError under declare(strict_types=1) (#18633, ext/standard/html.c)
--FILE--
<?php
declare(strict_types=1);

htmlspecialchars_decode(null);
--EXPECT--
--EXPECT_EXIT--
134
