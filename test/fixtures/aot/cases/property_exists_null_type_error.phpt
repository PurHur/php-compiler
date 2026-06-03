--TEST--
AOT: property_exists() — TypeError for null object_or_class (#4787, ext/standard/class.c)
--FILE--
<?php
property_exists(null, 'x');
--EXPECT--
--EXPECT_EXIT--
134
