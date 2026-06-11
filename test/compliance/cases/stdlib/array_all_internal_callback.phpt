--TEST--
stdlib array_all/array_any/array_find — internal function string callbacks (#6543)
--FILE--
<?php
var_dump(array_all([1, 2, 3], 'is_int'));
var_dump(array_any([1, 2, 3], 'is_string'));
var_dump(array_find([1, 2, 3], 'is_int'));
var_dump(array_all(['a', 'bb'], 'strlen'));
--EXPECT--
bool(true)
bool(false)
int(1)
bool(true)
