--TEST--
JIT date idate()/strftime()/date_parse()/mktime()/gmmktime(null) soft-null on 8.4 (#24862/#21491/#21582)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null')) {
        echo "DEP\n";
        return true;
    }
    if (E_WARNING === $no) {
        echo "WARN\n";
        return true;
    }
    return false;
});
foreach ([
    'idate' => static fn () => idate(null),
    'strftime' => static fn () => @strftime(null),
    'date_parse' => static fn () => date_parse(null),
    'mktime' => static fn () => mktime(null),
    'gmmktime' => static fn () => gmmktime(null),
] as $name => $call) {
    try {
        $r = $call();
        if ('idate' === $name) {
            echo "{$name}: OK ", var_export($r, true), "\n";
        } elseif ('mktime' === $name || 'gmmktime' === $name) {
            echo "{$name}: OK ", (is_int($r) ? 'int' : gettype($r)), "\n";
        } elseif ('date_parse' === $name) {
            echo "{$name}: COERCE error_count=", $r['error_count'], "\n";
        } else {
            echo "{$name}: COERCE\n";
        }
    } catch (TypeError $e) {
        echo "{$name}: TypeError\n";
    }
}
?>
--EXPECT--
DEP
WARN
idate: OK false
DEP
strftime: COERCE
DEP
date_parse: COERCE error_count=1
DEP
mktime: OK int
DEP
gmmktime: OK int
