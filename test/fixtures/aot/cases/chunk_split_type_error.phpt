--TEST--
AOT: chunk_split() — TypeError for non-string $string (#4580)
--FILE--
<?php
chunk_split([]);
--EXPECT--
--EXPECT_EXIT--
134
