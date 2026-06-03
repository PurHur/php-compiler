--TEST--
AOT: strspn()/strcspn() — TypeError for non-string operands
--FILE--
<?php
strspn([], 'a');
--EXPECT--
--EXPECT_EXIT--
134
