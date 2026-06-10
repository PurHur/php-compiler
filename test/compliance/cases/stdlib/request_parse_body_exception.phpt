--TEST--
stdlib RequestParseBodyException builtin class (ext/standard/http.c, #6993)
--FILE--
<?php
var_export(class_exists('RequestParseBodyException', false));
echo "\n";
var_export(is_subclass_of('RequestParseBodyException', 'Exception'));
echo "\n";
var_export(is_a('RequestParseBodyException', 'Throwable', true));
echo "\n";

try {
    throw new RequestParseBodyException('malformed body');
} catch (RequestParseBodyException $e) {
    echo 'caught:', $e->getMessage(), "\n";
} catch (Exception $e) {
    echo 'parent_catch:', $e->getMessage(), "\n";
}
--EXPECT--
true
true
true
caught:malformed body
