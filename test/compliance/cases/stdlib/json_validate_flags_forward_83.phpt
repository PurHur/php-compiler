--TEST--
stdlib json_validate() — only 0|JSON_INVALID_UTF8_IGNORE (issue #29069, #4085; php-src json.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
define('JSON_INVALID_UTF8_IGNORE', 1048576);
define('JSON_INVALID_UTF8_SUBSTITUTE', 2097152);

echo json_validate('{"a":1}', 512, JSON_INVALID_UTF8_IGNORE) ? '1' : '0';
echo "\n";

$bad = '{"x":"' . "\xC3\x28" . '"}';
echo json_validate($bad, 512, 0) ? '1' : '0';
echo "\n";
echo json_validate($bad, 512, JSON_INVALID_UTF8_IGNORE) ? '1' : '0';
echo "\n";

foreach ([JSON_INVALID_UTF8_SUBSTITUTE, JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE, JSON_INVALID_UTF8_IGNORE | 1] as $flags) {
    try {
        json_validate($bad, 512, $flags);
        echo "no-error\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
1
0
1
json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)
json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)
json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)
