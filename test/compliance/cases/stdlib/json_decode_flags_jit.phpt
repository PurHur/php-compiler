--TEST--
JIT: json_decode() JSON_THROW_ON_ERROR on compile-time literal (#3267)
--JIT--
--FILE--
<?php
try {
    json_decode('{invalid', true, 512, JSON_THROW_ON_ERROR);
    echo "no-throw\n";
} catch (JsonException $e) {
    echo "caught:", $e->getMessage(), "\n";
}

echo json_decode('{"x":1}', true, 2, 0)['x'], "\n";
?>
--EXPECT--
caught:Syntax error
1
