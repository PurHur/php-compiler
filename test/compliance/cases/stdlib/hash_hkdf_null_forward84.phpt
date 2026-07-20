--TEST--
stdlib hash_hkdf() null algo/key DEP+coerce then ValueError on 8.4 (#21319, reverts #21079)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
try {
    $r = hash_hkdf('sha256', null);
    echo 'key uncaught ', var_export($r, true), "\n";
} catch (ValueError $e) {
    echo "key:VE\n";
} catch (TypeError $e) {
    echo "key:TE\n";
}
try {
    hash_hkdf(null, 'k');
    echo "algo:OK\n";
} catch (ValueError $e) {
    echo "algo:VE\n";
} catch (TypeError $e) {
    echo "algo:TE\n";
}
try {
    hash_hkdf('sha256', '');
    echo "empty key uncaught\n";
} catch (ValueError $e) {
    echo "empty:VE\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 1), "\n";
?>
--EXPECT--
key:VE
algo:VE
empty:VE
depr=1
