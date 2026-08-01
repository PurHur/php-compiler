--TEST--
stdlib implode/join(",", null) dual-arg TypeError on PROFILE=8.4 (#26278)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
implode_separator_null_pieces_forward84.php
--EXPECT--
implode(",", null) => TypeError:implode(): If argument #1 ($separator) is of type string, argument #2 ($array) must be of type array, null given
join(",", null) => TypeError:join(): If argument #1 ($separator) is of type string, argument #2 ($array) must be of type array, null given
implode(",", ["a","b"]) => 'a,b'
