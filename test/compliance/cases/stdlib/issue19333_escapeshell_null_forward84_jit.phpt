--TEST--
stdlib escapeshellarg/escapeshellcmd null — soft-null on 8.4 forward profile JIT (#21221, re-#19333, ext/standard/exec.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
$n = null;
echo var_export(escapeshellarg($n), true), "\n";
echo var_export(escapeshellcmd($n), true), "\n";
echo var_export(escapeshellarg(''), true), "\n";
echo var_export(escapeshellcmd(''), true), "\n";
?>
--EXPECT--
DEP
'\'\''
DEP
''
'\'\''
''
