--TEST--
parse_ini_string unterminated quote warning matches Zend (#29358, zend_ini_scanner.l)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo 'WARN:', $errstr, "\n";

    return true;
});
var_export(parse_ini_string('a="unterminated'));
echo "\n";
var_export(parse_ini_string("a=\"line1\nstill"));
echo "\n";
?>
--EXPECT--
WARN:syntax error, unexpected end of file, expecting TC_DOLLAR_CURLY or TC_QUOTED_STRING or '"' in Unknown on line 1
false
WARN:syntax error, unexpected end of file, expecting TC_DOLLAR_CURLY or TC_QUOTED_STRING or '"' in Unknown on line 2
false
