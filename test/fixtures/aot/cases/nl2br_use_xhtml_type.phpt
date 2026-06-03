--TEST--
AOT: nl2br() — TypeError when $use_xhtml is array (#5056)
--FILE--
<?php
echo nl2br("a\nb", []);
--EXPECT--
--EXPECT_EXIT--
134
