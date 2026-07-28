--TEST--
stdlib array_fill_keys() — __toString object keys stringify (ext/standard/array.c #24035)
--FILE--
<?php
$o = new class {
    public function __toString(): string
    {
        return 'k';
    }
};
$r = array_fill_keys([$o], 1);
echo isset($r['k']) ? (string) $r['k'] : 'missing', "\n";
try {
    array_fill_keys([new stdClass()], 1);
    echo "noerr\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$num = new class {
    public function __toString(): string
    {
        return '5';
    }
};
$n = array_fill_keys([$num], 'v');
echo isset($n[5]) ? $n[5] : 'missing', "\n";
--EXPECT--
1
Object of class stdClass could not be converted to string
v
