--TEST--
AOT: mb_ereg_replace()/mb_decode_mimeheader() null TypeError under strict_types (#30311)
--FILE--
<?php
declare(strict_types=1);

mb_ereg_replace(null, "b", "c");
--EXPECT--
--EXPECT_EXIT--
255
