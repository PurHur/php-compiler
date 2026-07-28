<?php
// #24035 — array_fill_keys() object keys with __toString (php-src ext/standard/array.c)
$o = new class {
    public function __toString(): string
    {
        return 'k';
    }
};
$r = array_fill_keys([$o], 1);
echo isset($r['k']) ? (string) $r['k'] : 'missing';
echo "\n";
$bad = new class {};
try {
    array_fill_keys([$bad], 1);
    echo "noerr\n";
} catch (Error $e) {
    echo (str_contains($e->getMessage(), 'could not be converted to string') ? 'error' : $e->getMessage()), "\n";
}
$num = new class {
    public function __toString(): string
    {
        return '5';
    }
};
$n = array_fill_keys([$num], 'v');
echo isset($n[5]) ? $n[5] : 'missing';
echo "\n";
