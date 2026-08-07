--TEST--
stdlib forward-profile gated builtins on PHP_COMPILER_PROFILE=8.4 (#17319, #21481, #28366)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['getmygrgid', 'strxfrm', 'convert_cyr_string', 'money_format', 'getmygid'] as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', "\n";
}
--EXPECT--
getmygrgid=0
strxfrm=1
convert_cyr_string=0
money_format=0
getmygid=1
