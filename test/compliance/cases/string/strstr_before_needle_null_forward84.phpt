--TEST--
stdlib strstr/stristr/strchr null $before_needle soft-null DEP+false (#21702, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
foreach (['strstr', 'stristr', 'strchr'] as $fn) {
    $seen = 0;
    $msg = '';
    set_error_handler(static function (int $no, string $m) use (&$seen, &$msg): bool {
        if (E_DEPRECATED === $no) {
            $seen++;
            $msg = $m;
        }
        return true;
    });
    try {
        $hay = 'strstr' === $fn || 'strchr' === $fn ? 'abc' : 'Abc';
        $r = $fn($hay, 'b', null);
        echo $fn, '=', var_export($r, true), ' depr=', (int) ($seen >= 1);
        if ($seen >= 1 && false !== strpos($msg, 'parameter #3 ($before_needle)')) {
            echo ' idx=ok';
        }
        echo "\n";
    } catch (Throwable $e) {
        echo $fn, '=', get_class($e), ': ', $e->getMessage(), "\n";
    }
    restore_error_handler();
}
?>
--EXPECT--
strstr='bc' depr=1 idx=ok
stristr='bc' depr=1 idx=ok
strchr='bc' depr=1 idx=ok
