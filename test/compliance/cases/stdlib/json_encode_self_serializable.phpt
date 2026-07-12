--TEST--
stdlib json_encode() self-referencing JsonSerializable — '{}' not false (#17706, ext/json/php_json_encoder.c)
--FILE--
<?php
class SelfSerializable implements JsonSerializable {
    public function jsonSerialize(): mixed {
        return $this;
    }
}
$encoded = json_encode(new SelfSerializable());
echo $encoded, "\n";
var_export($encoded);
echo "\n";
echo json_last_error() === 0 ? '0' : (string) json_last_error(), "\n";
--EXPECT--
{}
'{}'
0
