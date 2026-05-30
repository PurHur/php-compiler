--TEST--
Language: count() TypeError on non-Countable object (VM, #3364)
--FILE--
<?php
echo count(new stdClass());
--EXPECT_EXIT--
255
