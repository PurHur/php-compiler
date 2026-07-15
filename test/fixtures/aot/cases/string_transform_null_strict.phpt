--TEST--
AOT: nl2br()/wordwrap()/stripslashes() — null operand TypeError (#18358, ext/standard/string.c)
--FILE--
<?php
nl2br(null);
--EXPECT--
--EXPECT_EXIT--
134
