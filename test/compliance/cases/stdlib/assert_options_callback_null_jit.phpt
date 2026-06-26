--TEST--
Stdlib: assert_options(ASSERT_CALLBACK) unset returns NULL JIT (#12290)
--FILE--
<?php
declare(strict_types=1);
var_export(assert_options(ASSERT_CALLBACK));
echo "\n";
?>
--EXPECT--
NULL
