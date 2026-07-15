--TEST--
stdlib strlen/strtolower/strtoupper/strrev null TypeError on 8.4 forward profile JIT (#19276)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
strlen_case_rev_null_typeerror_84.php
--EXPECT--
strlen: strlen(): Argument #1 ($string) must be of type string, null given
strtolower: strtolower(): Argument #1 ($string) must be of type string, null given
strtoupper: strtoupper(): Argument #1 ($string) must be of type string, null given
strrev: strrev(): Argument #1 ($string) must be of type string, null given
