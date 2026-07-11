--TEST--
AOT func_get_args() global scope Error (issue #17916)
--FILE--
<?php
func_get_args();
--EXPECT--

--EXPECT_EXIT--
134
