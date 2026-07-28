--TEST--
stdlib array_combine() — __toString object keys stringify (ext/standard/array.c #24036)
--FILE--
<?php
$o = new class {
    public function __toString(): string
    {
        return 'k';
    }
};
$r = array_combine([$o], [1]);
echo isset($r['k']) ? (string) $r['k'] : 'missing', "\n";
try {
    array_combine([new stdClass()], [1]);
    echo "noerr\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1
Object of class stdClass could not be converted to string
