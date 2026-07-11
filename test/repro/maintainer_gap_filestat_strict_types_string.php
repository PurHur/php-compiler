<?php

declare(strict_types=1);

$tmpdir = sys_get_temp_dir();
$errors = 0;

try {
    $f = $tmpdir.'/phpc_strict_chmod_'.uniqid('', true).'.tmp';
    touch($f);
    chmod($f, '0644');
    echo "chmod: expected TypeError\n";
    ++$errors;
    @unlink($f);
} catch (TypeError $e) {
    echo "chmod: ok\n";
} catch (Throwable $e) {
    echo 'chmod: '.get_class($e)."\n";
    ++$errors;
}

try {
    $d = $tmpdir.'/phpc_strict_mkdir_'.uniqid('', true);
    mkdir($d, '0755');
    echo "mkdir: expected TypeError\n";
    ++$errors;
    @rmdir($d);
} catch (TypeError $e) {
    echo "mkdir: ok\n";
} catch (Throwable $e) {
    echo 'mkdir: '.get_class($e)."\n";
    ++$errors;
}

try {
    $f = $tmpdir.'/phpc_strict_touch_'.uniqid('', true).'.tmp';
    touch($f, '123');
    echo "touch: expected TypeError\n";
    ++$errors;
    @unlink($f);
} catch (TypeError $e) {
    echo "touch: ok\n";
} catch (Throwable $e) {
    echo 'touch: '.get_class($e)."\n";
    ++$errors;
}

$f = $tmpdir.'/phpc_strict_chmod_int_'.uniqid('', true).'.tmp';
touch($f);
chmod($f, 0644);
@unlink($f);
echo "chmod_int: ok\n";

exit($errors > 0 ? 1 : 0);
