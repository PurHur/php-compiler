--TEST--
pathinfo/fnmatch/preg_match(null $flags) DEP+coerce on 8.4 (#21736)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
issue_21736_null_flags.php
--EXPECT--
DEP
pathinfo OK
DEP
fnmatch OK: true
DEP
preg_match OK: 1
