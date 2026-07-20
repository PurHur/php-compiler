--TEST--
stdlib strip_tags/strtr/str_split/count_chars/str_word_count/str_ireplace/str_getcsv null — DEP+coerce on 8.4 (#21207)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    ++$deps;
    return true;
});
$cases = [
    ['strip_tags', [null]],
    ['strtr', [null, 'a', 'b']],
    ['str_split', [null]],
    ['count_chars', [null, 1]],
    ['str_word_count', [null]],
    ['str_ireplace', ['a', 'b', null]],
    ['str_getcsv', [null]],
];
foreach ($cases as [$f, $a]) {
    $prev = $deps;
    try {
        $r = $f(...$a);
        echo $f, ($deps > $prev ? ' DEP' : ''), " OK\n";
    } catch (\Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
?>
--EXPECT--
strip_tags DEP OK
strtr DEP OK
str_split DEP OK
count_chars DEP OK
str_word_count DEP OK
str_ireplace DEP OK
str_getcsv DEP OK
