--TEST--
AOT: disk_free_space() — TypeError for non-string path (#4915, ext/standard/filestat.c)
--FILE--
<?php
disk_free_space([]);

--EXPECT--
--EXPECT_EXIT--
134
