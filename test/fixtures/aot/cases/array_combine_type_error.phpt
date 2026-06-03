--TEST--
AOT: array_combine() — TypeError for non-array operands (#4714)
--FILE--
<?php
array_combine('keys', [1]);
--EXPECT--
--EXPECT_EXIT--
134
