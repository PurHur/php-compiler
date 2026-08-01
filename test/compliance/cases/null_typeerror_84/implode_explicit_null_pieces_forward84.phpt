--TEST--
stdlib implode([…], null) TypeError on PROFILE=8.4; join keeps array-first (#26277)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
implode_explicit_null_pieces_forward84.php
--EXPECT--
implode([1,2], null) => TypeError:implode(): Argument #1 ($separator) must be of type string, array given
implode([], null) => TypeError:implode(): Argument #1 ($separator) must be of type string, array given
implode([1,2]) => '12'
join([1,2], null) => '12'
