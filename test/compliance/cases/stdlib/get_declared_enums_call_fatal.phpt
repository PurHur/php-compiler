--TEST--
Stdlib: get_declared_enums() call fatal — not in php-src (VM, #11248)
--FILE--
<?php
get_declared_enums();
--EXPECTF--
%ACall to undefined function get_declared_enums()%A
--EXPECT_EXIT--
255
