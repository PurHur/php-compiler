--TEST--
AOT: basename()/dirname() — TypeError for wrong operand types (#4715)
--FILE--
<?php
dirname('/a/b/c', []);
--EXPECT--
--EXPECT_EXIT--
134
