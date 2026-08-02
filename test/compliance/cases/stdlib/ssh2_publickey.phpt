--TEST--
stdlib ssh2_publickey_* registration (#26717)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
$fns = [
    'ssh2_publickey_init',
    'ssh2_publickey_add',
    'ssh2_publickey_remove',
    'ssh2_publickey_list',
];
foreach ($fns as $fn) {
    if (!function_exists($fn)) {
        echo "skip\n";
        exit(0);
    }
}
echo implode(',', array_map(static fn (string $f): string => function_exists($f) ? '1' : '0', $fns));
echo "\n";
?>
--EXPECT--
1,1,1,1
