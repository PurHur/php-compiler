--TEST--
Language: JsonSerializable — json_encode() via jsonSerialize() (VM, #3370)
--FILE--
<?php
class Payload implements JsonSerializable {
    public function jsonSerialize(): mixed {
        return ['id' => 1, 'label' => 'ok'];
    }
}
class Plain {}
echo json_encode(new Payload()), "\n";
var_export(json_encode(new Plain()));
echo "\n";
echo json_last_error() === 8 ? '8' : 'n', "\n";
echo interface_exists(JsonSerializable::class) ? '1' : '0', "\n";
--EXPECT--
{"id":1,"label":"ok"}
false
8
1
