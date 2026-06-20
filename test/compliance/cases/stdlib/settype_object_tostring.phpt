--TEST--
stdlib settype() object to string — __toString or Error (ext/standard/type.c, #10211)
--FILE--
<?php
class WithToString {
    public function __toString(): string {
        return 'ok';
    }
}
class WithoutToString {}

$x = new WithToString();
settype($x, 'string');
echo $x, "\n";

$y = new WithoutToString();
try {
    settype($y, 'string');
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
ok
Object of class WithoutToString could not be converted to string
