--TEST--
stdlib json_encode() flags and JSON_* constants (issue #3281)
--FILE--
<?php
echo json_encode(['a' => 'b'], JSON_PRETTY_PRINT);
echo "\n---\n";

echo json_encode(['slash' => 'a/b'], JSON_UNESCAPED_SLASHES), "\n";

echo json_encode(['pi' => "\xCF\x80"], JSON_UNESCAPED_UNICODE), "\n";

echo json_encode(['a' => 'b'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n---\n";

echo JSON_PRETTY_PRINT, ' ', JSON_UNESCAPED_UNICODE, ' ', JSON_THROW_ON_ERROR, "\n";

class BadResource implements JsonSerializable {
    public function jsonSerialize(): mixed {
        return fopen('php://memory', 'r');
    }
}

var_export(json_encode(new BadResource()));
echo " err=", json_last_error(), "\n";

try {
    json_encode(new BadResource(), JSON_THROW_ON_ERROR);
    echo "no-throw\n";
} catch (JsonException $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
{
    "a": "b"
}
---
{"slash":"a/b"}
{"pi":"π"}
{
    "a": "b"
}
---
128 256 4194304
false err=8
Type is not supported
