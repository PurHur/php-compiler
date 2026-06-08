--TEST--
stdlib json_encode() on Stringable objects encodes as {} (#6879, ext/json/php_json.c)
--FILE--
<?php
declare(strict_types=1);

class C implements Stringable {
    public function __toString(): string {
        return 'hi';
    }
}

class D {
    public int $x = 1;
}

var_export(json_encode(new C()));
echo "\n";
echo json_last_error(), "\n";
echo json_encode(new D()), "\n";
--EXPECT--
'{}'
0
{"x":1}
