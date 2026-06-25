--TEST--
stdlib get_current_user() returns script file owner when executed from disk (issue #11755)
--RUNFILE--
test/compliance/cases/stdlib/get_current_user_file_run.php
--FILE--
<?php
// body in RUNFILE
--EXPECT--
user
