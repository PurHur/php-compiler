--TEST--
Stdlib: filter_var() FILTER_SANITIZE_NUMBER_INT JIT (#11419)
--FILE--
<?php
echo filter_var('123abc', FILTER_SANITIZE_NUMBER_INT), "\n";
--EXPECT--
123
