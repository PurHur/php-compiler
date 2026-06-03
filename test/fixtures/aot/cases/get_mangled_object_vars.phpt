--TEST--
AOT: get_mangled_object_vars() — TypeError for null (#3497, ext/standard/var.c)
--FILE--
<?php
get_mangled_object_vars(null);
--EXPECT--
--EXPECT_EXIT--
134
