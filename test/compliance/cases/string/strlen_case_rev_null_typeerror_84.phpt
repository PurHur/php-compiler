--TEST--
stdlib strlen/case/rev soft-null; bin2hex TypeError on 8.4 forward profile (#20007/#20154)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
strlen_case_rev_null_typeerror_84.php
--EXPECT--
strlen: uncaught 0
strtolower: uncaught ''
strtoupper: uncaught ''
strrev: uncaught ''
bin2hex: bin2hex(): Argument #1 ($string) must be of type string, null given
