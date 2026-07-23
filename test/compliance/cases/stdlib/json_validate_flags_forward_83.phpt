--TEST--
stdlib json_validate() — JSON_INVALID_UTF8_* flags (issue #4085, #22544)
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
echo json_validate($bad, 512, JSON_INVALID_UTF8_SUBSTITUTE) ? '1' : '0';
echo "\n";

try {
    json_validate('[]', 512, JSON_INVALID_UTF8_IGNORE | 1);
    echo "no-error\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
1
0
1
1
json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE, JSON_INVALID_UTF8_SUBSTITUTE)
