--TEST--
stdlib json_decode() malformed UTF-8 JsonException message (#10518)
--FILE--
<?php
declare(strict_types=1);
try {
    json_decode("\xFF", flags: JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    echo $e->getMessage(), "\n";
    echo $e->getCode(), "\n";
}
?>
--EXPECT--
Malformed UTF-8 characters, possibly incorrectly encoded
5
