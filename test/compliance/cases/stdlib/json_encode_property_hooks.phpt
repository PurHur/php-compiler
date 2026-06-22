--TEST--
json_encode() on objects with property hooks invokes get hooks (#8732, ext/json/php_json.c)
--FILE--
<?php
class User {
    public string $name {
        get => strtoupper($this->name);
        set => $value;
    }
}
$u = new User();
$u->name = 'alice';
echo json_encode($u), "\n";
--EXPECT--
{"name":"ALICE"}
