--TEST--
Language: encapsed object r-value — call arg, assign, exception message (#13466)
--FILE--
<?php
class C {
    public string $p = 'prop';

    public function __toString(): string {
        return 'obj';
    }
}

$c = new C();
var_export("{$c}");
echo "\n";
var_export("{$c->p}");
echo "\n";

$a = [1 => 'one'];
var_export("{$a[1]}");
echo "\n";

$s = "prefix{$c}suffix";
var_export($s);
echo "\n";

try {
    throw new Exception("err: {$c}");
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}

echo "prefix{$c}suffix\n";
--EXPECT--
'obj'
'prop'
'one'
'prefixobjsuffix'
err: obj
prefixobjsuffix
