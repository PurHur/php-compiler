--TEST--
AOT: strlen() — TypeError when argument is null (#4365)
--FILE--
<?php
strlen(null);
--EXPECT--
--EXPECT_EXIT--
134
