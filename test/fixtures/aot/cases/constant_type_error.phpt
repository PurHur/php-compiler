--TEST--
AOT: constant() — TypeError for non-string name (#4846)
--FILE--
<?php
constant(1);
--EXPECT--
--EXPECT_EXIT--
134
