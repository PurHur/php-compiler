--TEST--
stdlib array_all() — closure wrapping internal predicate (#6543, #13946)
--FILE--
<?php
var_dump(array_all(['a', 'bb'], fn ($v) => strlen($v) > 0));
var_dump(array_any([1, 2, 3], fn ($v) => is_string($v)));
var_dump(array_find([1, 2, 3], fn ($v) => is_int($v)));
--EXPECT--
bool(true)
bool(false)
int(1)
