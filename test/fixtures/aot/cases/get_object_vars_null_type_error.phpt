--TEST--
AOT: get_object_vars() — TypeError for null (#4813)
--FILE--
<?php
get_object_vars(null);
--EXPECT--
--EXPECT_EXIT--
134
