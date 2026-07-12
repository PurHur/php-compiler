--TEST--
stdlib string transform builtins — null $string coerces to empty string JIT (#18263, ext/standard/string.c)
--JIT--
--FILE--
<?php
foreach (['str_rot13', 'str_shuffle', 'str_repeat', 'hebrev'] as $fn) {
    if ('str_repeat' === $fn) {
        var_export($fn(null, 2));
    } else {
        var_export($fn(null));
    }
    echo "\n";
}
--EXPECT--
''
''
''
''
