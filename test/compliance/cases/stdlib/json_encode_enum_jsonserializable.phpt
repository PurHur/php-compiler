--TEST--
stdlib json_encode() — enum JsonSerializable invokes jsonSerialize() (#6880, ext/json/php_json.c)
--FILE--
<?php
enum UnitJson implements JsonSerializable {
    case A;
    public function jsonSerialize(): mixed {
        return 'a';
    }
}
enum BackedJson: string implements JsonSerializable {
    case X = 'x';
    public function jsonSerialize(): mixed {
        return 'custom';
    }
}
enum Status: int implements JsonSerializable {
    case Ok = 1;
    public function jsonSerialize(): mixed {
        return ['status' => 'ok'];
    }
}
enum Plain: string {
    case Y = 'y';
}
echo json_encode(UnitJson::A), "\n";
echo json_encode(BackedJson::X), "\n";
echo json_encode(Status::Ok), "\n";
echo json_encode(Plain::Y), "\n";
--EXPECT--
"a"
"custom"
{"status":"ok"}
"y"
