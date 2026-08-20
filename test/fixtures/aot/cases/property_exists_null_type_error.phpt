--TEST--
AOT: property_exists() — uncaught TypeError for null object_or_class (#4787 / #33054, ext/standard/class.c)
--FILE--
<?php
property_exists(null, 'x');
--EXPECT--
--EXPECT_EXIT--
255
