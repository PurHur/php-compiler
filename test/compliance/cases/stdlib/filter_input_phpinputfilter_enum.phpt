--TEST--
stdlib PhpInputFilter enum for filter_input() (#7284, ext/filter/filter.stub.php)
--FILE--
<?php
var_export(enum_exists('PhpInputFilter', false));
echo "\n";
var_export(PhpInputFilter::Get->name);
echo "\n";
var_export(PhpInputFilter::Get->value === INPUT_GET);
echo "\n";
var_export(PhpInputFilter::Post->value === INPUT_POST);
echo "\n";
var_export(PhpInputFilter::Cookie->value === INPUT_COOKIE);
echo "\n";
var_export(filter_input(INPUT_GET, 'missing', FILTER_VALIDATE_INT) === null);
echo "\n";
var_export(filter_input(PhpInputFilter::Get, 'missing', FILTER_VALIDATE_INT) === null);
echo "\n";
enum Es: string { case B = 'hi'; }
try {
    filter_input(Es::B, 'missing', FILTER_VALIDATE_INT);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
'Get'
true
true
true
true
true
filter_input(): Argument #1 ($type) must be of type PhpInputFilter|int, Es given
