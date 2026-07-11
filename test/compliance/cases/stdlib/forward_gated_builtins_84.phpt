--TEST--
stdlib forward-profile gated builtins on PHP_COMPILER_PROFILE=8.4 (#17319)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['getmygrgid', 'strxfrm', 'convert_cyr_string'] as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', "\n";
}
--EXPECT--
getmygrgid=1
strxfrm=1
convert_cyr_string=1
