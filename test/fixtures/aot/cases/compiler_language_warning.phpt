--TEST--
AOT: compiler_language_warning() emits E_WARNING via LLVM (#9214, Zend zend_compile.c)
--FILE--
<?php
compiler_language_warning('"continue" targeting switch is equivalent to "break"', 8);
echo compiler_language_warning('probe') ? '1' : '0';
echo "\nok\n";
--EXPECT--
1
ok
