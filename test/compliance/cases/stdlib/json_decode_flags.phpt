--TEST--
stdlib json_decode() depth, flags, and JSON_THROW_ON_ERROR (#3267, ext/json/php_json.c)
--FILE--
<?php
$bad = '{invalid';
try {
    json_decode($bad, true, 512, JSON_THROW_ON_ERROR);
    echo "no-throw\n";
} catch (JsonException $e) {
    echo "caught:", $e->getMessage(), "\n";
}

var_export(json_decode($bad, true));
echo " err=", json_last_error(), "\n";

$nested = '{"a":{"b":1}}';
var_export(json_decode($nested, true, 1));
echo " depth-err=", json_last_error(), "\n";

var_export(json_decode('{"ok":1}', true, 512, 0));
echo "\n";
?>
--EXPECT--
caught:Syntax error
NULL err=4
NULL depth-err=1
array (
  'ok' => 1,
)
