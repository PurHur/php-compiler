--TEST--
stdlib forward-profile gated builtins on PHP_COMPILER_PROFILE=8.4 (#17319, #21481)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['getmygrgid', 'strxfrm', 'convert_cyr_string', 'money_format'] as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', "\n";
}
--EXPECT--
getmygrgid=1
strxfrm=1
convert_cyr_string=0
money_format=0
