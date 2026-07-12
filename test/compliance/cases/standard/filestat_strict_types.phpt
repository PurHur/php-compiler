--TEST--
filestat builtins reject string int operands under declare(strict_types=1) (#17927, ext/standard/filestat.c)
--FILE--
<?php

declare(strict_types=1);

$tmpdir = sys_get_temp_dir();
$ok = 0;

try {
    $f = $tmpdir.'/phpc_filestat_strict_chmod_'.uniqid('', true).'.tmp';
    touch($f);
    chmod($f, '0644');
    echo "chmod-bad\n";
    @unlink($f);
} catch (TypeError $e) {
    echo "chmod\n";
    ++$ok;
}

try {
    $d = $tmpdir.'/phpc_filestat_strict_mkdir_'.uniqid('', true);
    mkdir($d, '0755');
    echo "mkdir-bad\n";
    @rmdir($d);
} catch (TypeError $e) {
    echo "mkdir\n";
    ++$ok;
}

try {
    $f = $tmpdir.'/phpc_filestat_strict_touch_'.uniqid('', true).'.tmp';
    touch($f, '123');
    echo "touch-bad\n";
    @unlink($f);
} catch (TypeError $e) {
    echo "touch\n";
    ++$ok;
}

$f = $tmpdir.'/phpc_filestat_strict_int_'.uniqid('', true).'.tmp';
touch($f);
chmod($f, 0644);
@unlink($f);
echo "int\n";
--EXPECT--
chmod
mkdir
touch
int
