--TEST--
stdlib implode(null)/join(null) dual-arg TypeError on PROFILE=8.4 (#29591)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
implode_join_null_one_arg_forward84.php
--EXPECT--
implode(null) => TypeError: implode(): If argument #1 ($separator) is of type string, argument #2 ($array) must be of type array, null given
Deprecated: join(): Passing null to parameter #1 ($separator) of type array|string is deprecated
join(null) => TypeError: join(): If argument #1 ($separator) is of type string, argument #2 ($array) must be of type array, null given
Deprecated: implode(): Passing null to parameter #1 ($separator) of type array|string is deprecated
implode(null, ["a","b"]) => 'ab'

