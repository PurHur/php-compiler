--TEST--
AOT get_class() — TypeError for null (#5456)
--FILE--
<?php
get_class(null);
--EXPECT--
--EXPECT_EXIT--
134
