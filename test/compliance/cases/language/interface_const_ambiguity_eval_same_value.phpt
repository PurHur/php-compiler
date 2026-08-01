--TEST--
Language: ambiguous interface constants via eval() — identical values still fatal (#26672)
--FILE--
<?php
interface I { const C = 1; }
interface J { const C = 1; }
eval('class X implements I, J {} return X::C;');
echo "EVAL_OK\n";
--EXPECTF--
PHP Fatal error:  Class X inherits both I::C and J::C, which is ambiguous in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
