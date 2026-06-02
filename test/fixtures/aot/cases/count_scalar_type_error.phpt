--TEST--
AOT: count() TypeError on string via JIT pending error (issue #4501)
--FILE--
<?php
count('abc');
--EXPECT--
--EXPECT_EXIT--
255
