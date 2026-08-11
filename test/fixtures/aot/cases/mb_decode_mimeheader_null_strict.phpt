--TEST--
AOT: mb_decode_mimeheader() null TypeError under strict_types (#30311)
--FILE--
<?php
declare(strict_types=1);

mb_decode_mimeheader(null);
--EXPECT--
--EXPECT_EXIT--
255
