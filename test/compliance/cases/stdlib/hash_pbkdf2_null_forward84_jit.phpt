--TEST--
stdlib hash_pbkdf2() null password/salt DEP+coerce on 8.4 JIT (#21319, reverts #20659)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
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
    $r = hash_pbkdf2('sha256', null, 'salt', 1);
    echo 'password=', var_export($r === hash_pbkdf2('sha256', '', 'salt', 1), true), "\n";
} catch (TypeError $e) {
    echo "password:TE\n";
}
try {
    $r = hash_pbkdf2('sha256', 'p', null, 1);
    echo 'salt=', var_export($r === hash_pbkdf2('sha256', 'p', '', 1), true), "\n";
} catch (TypeError $e) {
    echo "salt:TE\n";
}
try {
    hash_pbkdf2(null, 'p', 's', 1);
    echo "algo:OK\n";
} catch (ValueError $e) {
    echo "algo:VE\n";
} catch (TypeError $e) {
    echo "algo:TE\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 2), "\n";
echo var_export(hash_pbkdf2('sha256', '', 'salt', 1) !== '', true), "\n";
?>
--EXPECT--
password=true
salt=true
algo:VE
depr=1
true
