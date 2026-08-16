--TEST--
gzcompress/gzencode/gzdeflate null $level soft-null DEP JIT (#31445)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
foreach (['gzcompress', 'gzencode', 'gzdeflate'] as $f) {
    $r = $f('a', null);
    echo $f, ' ', is_string($r) && strlen($r) > 0 ? 'OK' : 'BAD', "\n";
}
--EXPECT--
ERR[8192]: gzcompress(): Passing null to parameter #2 ($level) of type int is deprecated
gzcompress OK
ERR[8192]: gzencode(): Passing null to parameter #2 ($level) of type int is deprecated
gzencode OK
ERR[8192]: gzdeflate(): Passing null to parameter #2 ($level) of type int is deprecated
gzdeflate OK
