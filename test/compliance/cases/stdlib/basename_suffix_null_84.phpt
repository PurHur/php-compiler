--TEST--
basename(..., null) $suffix DEP+coerce on 8.4 (#29705, ext/standard/basename.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
issue_29705_basename_suffix_null.php
--EXPECT--
DEP
suffix null OK 'foo.txt'
suffix ok 'foo'
