--TEST--
basename/dirname/pathinfo(null) DEP+coerce on 8.4 (#21779, ext/standard/basename.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
issue_21779_basename_dirname_null.php
--EXPECT--
DEP
basename OK ''
DEP
dirname OK ''
DEP
pathinfo OK 2
