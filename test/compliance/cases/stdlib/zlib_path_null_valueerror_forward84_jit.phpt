--TEST--
stdlib gzopen/gzfile/readgzfile(null) — empty-path ValueError on 8.4 — JIT (#21877, ext/zlib/zlib.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
set_error_handler(static function (int $n, string $m): bool {
    echo "DEP\n";
    return true;
});
foreach ([
    'gzopen' => static fn () => gzopen(null, 'r'),
    'gzfile' => static fn () => gzfile(null),
    'readgzfile' => static fn () => readgzfile(null),
] as $label => $fn) {
    try {
        $fn();
        echo $label, ": miss\n";
    } catch (ValueError $e) {
        echo $label, ':VALUEERROR:', $e->getMessage(), "\n";
    } catch (TypeError $e) {
        echo $label, ':TYPEERROR:', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
DEP
gzopen:VALUEERROR:Path cannot be empty
DEP
gzfile:VALUEERROR:Path cannot be empty
DEP
readgzfile:VALUEERROR:Path cannot be empty
