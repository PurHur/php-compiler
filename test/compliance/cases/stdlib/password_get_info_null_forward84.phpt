--TEST--
stdlib password_get_info(null) soft-null on 8.4 forward profile (#21537, reverts #20672)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
        echo 'DEP:', $str, "\n";
    }
    return true;
});
try {
    $info = password_get_info(null);
    echo $info['algoName'], "\n";
    echo null === $info['algo'] ? "algo_null\n" : "algo_set\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 1), "\n";
?>
--EXPECT--
DEP:password_get_info(): Passing null to parameter #1 ($hash) of type string is deprecated
unknown
algo_null
depr=1
