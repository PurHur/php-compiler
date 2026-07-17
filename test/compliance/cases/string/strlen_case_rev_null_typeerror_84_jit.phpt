--TEST--
stdlib strlen/strtolower/strtoupper/strrev/bin2hex null — coerce on 8.4 forward profile JIT (#20007)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
strlen_case_rev_null_typeerror_84.php
--EXPECT--
strlen: uncaught 0
strtolower: uncaught ''
strtoupper: uncaught ''
strrev: uncaught ''
bin2hex: uncaught ''
