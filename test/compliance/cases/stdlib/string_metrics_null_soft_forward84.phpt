--TEST--
stdlib levenshtein/similar_text/strcoll/strcspn/strspn/strtok soft-null on 8.4 (#21195)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
$cases = [
    ['levenshtein', [null, ''], 0],
    ['similar_text', [null, ''], 0],
    ['strcoll', [null, ''], 0],
    ['strcspn', [null, 'a'], 0],
    ['strspn', [null, 'a'], 0],
    ['strtok', [null, ' '], false],
];
foreach ($cases as [$f, $a, $expect]) {
    try {
        $r = $f(...$a);
        echo $f, ' ', ($r === $expect ? 'OK' : 'BAD '.var_export($r, true)), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
?>
--EXPECT--
DEP
levenshtein OK
DEP
similar_text OK
DEP
strcoll OK
DEP
strcspn OK
DEP
strspn OK
DEP
strtok OK
