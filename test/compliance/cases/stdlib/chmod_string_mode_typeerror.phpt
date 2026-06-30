--TEST--
stdlib chmod() string mode operand — TypeError not LogicException (#14023, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);

$f = sys_get_temp_dir() . '/phpc_chmod_str_mode_' . uniqid('', true) . '.tmp';
touch($f);
try {
    chmod($f, '0644');
    echo "fail: no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} finally {
    @unlink($f);
}
--EXPECT--
chmod(): Argument #2 ($permissions) must be of type int, string given
