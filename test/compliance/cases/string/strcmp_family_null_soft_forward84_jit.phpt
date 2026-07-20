--TEST--
stdlib strcmp family soft-null on 8.4 forward profile JIT (#21317, reverts #19298 TypeError, ext/standard/string.c)
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
    ['strcmp', static fn () => strcmp(null, 'a'), -1],
    ['strcasecmp', static fn () => strcasecmp(null, 'a'), -1],
    ['strncmp', static fn () => strncmp(null, 'a', 1), -1],
    ['strncasecmp', static fn () => strncasecmp(null, 'a', 1), -1],
    ['strcoll', static fn () => strcoll(null, 'a'), -97],
    ['strnatcmp', static fn () => strnatcmp(null, 'a'), -1],
    ['strnatcasecmp', static fn () => strnatcasecmp(null, 'a'), -1],
];
foreach ($cases as [$label, $factory, $expect]) {
    try {
        $r = $factory();
        echo $label, ' ', ($r === $expect ? 'OK' : 'BAD '.var_export($r, true)), "\n";
    } catch (Throwable $e) {
        echo $label, ' ', get_class($e), "\n";
    }
}
?>
--EXPECT--
DEP
strcmp OK
DEP
strcasecmp OK
DEP
strncmp OK
DEP
strncasecmp OK
DEP
strcoll OK
DEP
strnatcmp OK
DEP
strnatcasecmp OK
