--TEST--
zlib gzcompress/gzencode/gzdeflate/gzdecode/gzuncompress/gzinflate(null) — soft-null DEP+coerce on 8.4 (#21311/#21280, reverts #19332; ext/zlib/zlib.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    if (E_WARNING === $no) {
        echo "WARN\n";
        return true;
    }
    return false;
});
foreach (['gzcompress', 'gzencode', 'gzdeflate'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ': OK string len=', strlen($r), "\n";
    } catch (TypeError $e) {
        echo $fn, ": TypeError\n";
    }
}
foreach (['gzdecode', 'gzuncompress', 'gzinflate'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ': OK ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $fn, ": TypeError\n";
    }
}
echo strlen(gzcompress('')), "\n";
?>
--EXPECT--
DEP
gzcompress: OK string len=8
DEP
gzencode: OK string len=20
DEP
gzdeflate: OK string len=2
DEP
WARN
gzdecode: OK false
DEP
WARN
gzuncompress: OK false
DEP
WARN
gzinflate: OK false
8
