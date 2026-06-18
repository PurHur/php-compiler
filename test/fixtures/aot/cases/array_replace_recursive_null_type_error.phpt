--TEST--
AOT: array_replace_recursive() — TypeError for null argument (#9624)
--FILE--
<?php
array_replace_recursive(['a' => 1], null);
--EXPECT--
--EXPECT_EXIT--
134
