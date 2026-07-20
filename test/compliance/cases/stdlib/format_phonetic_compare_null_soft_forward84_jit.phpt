--TEST--
stdlib chunk_split/str_pad/wordwrap/soundex/metaphone/strcmp/strcasecmp soft-null on 8.4 JIT (#21190)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
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
    ['chunk_split', [null], "\r\n"],
    ['str_pad', [null, 5], '     '],
    ['wordwrap', [null], ''],
    ['soundex', [null], '0000'],
    ['metaphone', [null], ''],
    ['strcmp', [null, ''], 0],
    ['strcasecmp', [null, ''], 0],
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
chunk_split OK
DEP
str_pad OK
DEP
wordwrap OK
DEP
soundex OK
DEP
metaphone OK
DEP
strcmp OK
DEP
strcasecmp OK
