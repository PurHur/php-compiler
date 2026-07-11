--TEST--
stdlib error_get_last() — cleared when error handler returns true (#12300, basic_functions.c)
--FILE--
<?php
set_error_handler(static fn (): bool => true);
@trigger_error('suppressed', E_USER_NOTICE);
echo null === error_get_last() ? 'null' : 'set', "\n";
trigger_error('also suppressed', E_USER_NOTICE);
echo null === error_get_last() ? 'null2' : 'set2', "\n";
--EXPECT--
null
null2
