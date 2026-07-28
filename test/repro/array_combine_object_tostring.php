<?php
// #24036 — array_combine() object keys with __toString (php-src ext/standard/array.c)
$o = new class {
    public function __toString(): string
    {
        return 'k';
    }
};
$r = array_combine([$o], [1]);
echo isset($r['k']) ? (string) $r['k'] : 'missing';
echo "\n";
$bad = new class {};
try {
    array_combine([$bad], [1]);
    echo "noerr\n";
} catch (Error $e) {
    echo (str_contains($e->getMessage(), 'could not be converted to string') ? 'error' : $e->getMessage()), "\n";
}
