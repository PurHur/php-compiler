--TEST--
AOT: ucwords() — TypeError for non-string $string (#4950)
--FILE--
<?php
ucwords([]);
--EXPECT--
--EXPECT_EXIT--
134
