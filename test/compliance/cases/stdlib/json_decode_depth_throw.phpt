--TEST--
stdlib json_decode() depth limit with JSON_THROW_ON_ERROR (#10611, ext/json/php_json.c)
--FILE--
<?php
try {
    json_decode(str_repeat('[', 10_000), true, 512, JSON_THROW_ON_ERROR);
    echo "no-throw\n";
} catch (JsonException $e) {
    echo "caught:", $e->getMessage(), "\n";
}
?>
--EXPECT--
caught:Maximum stack depth exceeded
