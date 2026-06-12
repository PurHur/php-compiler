--TEST--
stdlib filter_var() FILTER_VALIDATE_REGEXP (issue #5020)
--FILE--
<?php
$ok = filter_var('abc123', FILTER_VALIDATE_REGEXP, [
    'options' => ['regexp' => '/^[a-z0-9]+$/'],
]);
var_dump($ok);
$bad = filter_var('!!!', FILTER_VALIDATE_REGEXP, [
    'options' => ['regexp' => '/^[a-z0-9]+$/'],
]);
var_dump($bad);
try {
    filter_var('x', FILTER_VALIDATE_REGEXP, []);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
var_dump(filter_var('x', FILTER_VALIDATE_REGEXP, [
    'options' => ['regexp' => '/[/'],
]) === false);
--EXPECT--
string(6) "abc123"
bool(false)
filter_var(): "regexp" option is missing
bool(true)
