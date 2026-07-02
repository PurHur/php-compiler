--TEST--
stdlib is_countable() — multi-arg var_dump with New_ prelude (#14958)
--FILE--
<?php
var_dump(is_countable(null), is_countable([]), is_countable(new ArrayObject()));
--EXPECT--
bool(false)
bool(true)
bool(true)
