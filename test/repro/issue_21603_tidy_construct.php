<?php
echo 'ctor=', (int) method_exists('tidy', '__construct'), "\n";
$t = new tidy();
echo 'empty=', get_class($t), "\n";
try {
    new tidy(__DIR__.'/no_such_tidy_file_21603.html');
    echo "missing=ok_unexpected\n";
} catch (Throwable $e) {
    echo 'missing=', get_class($e), "\n";
}
$tmp = tempnam(sys_get_temp_dir(), 'tidy21603_');
file_put_contents($tmp, '<p>hi</p>');
try {
    $t2 = new tidy($tmp);
    echo 'file=', get_class($t2), "\n";
} catch (Throwable $e) {
    echo 'file_err=', get_class($e), ':', $e->getMessage(), "\n";
}
@unlink($tmp);
