--TEST--
stdlib is_uploaded_file() (issue #2204)
--FILE--
<?php
declare(strict_types=1);
$base = 'test/compliance/cases/stdlib/move_uploaded_file_fixture';

$tmp = tempnam(sys_get_temp_dir(), 'phpc_upload_');
if (false === $tmp) {
    echo "notmp\n";
    exit(1);
}
$n = file_put_contents($tmp, 'upload-bytes');
if (false === $n) {
    echo "nowrite\n";
    exit(1);
}

if (is_uploaded_file($tmp)) {
    echo "ok\n";
} else {
    echo "fail\n";
}

$bogus = $base . '/from.txt';
file_put_contents($bogus, 'x');
if (is_uploaded_file($bogus)) {
    echo "bad\n";
} else {
    echo "reject\n";
}
@unlink($bogus);

$plain = tempnam(sys_get_temp_dir(), 'phpc_plain_');
if (false !== $plain) {
    file_put_contents($plain, 'x');
    if (is_uploaded_file($plain)) {
        echo "plain\n";
    } else {
        echo "noplain\n";
    }
    @unlink($plain);
}

@unlink($tmp);
--EXPECT--
ok
reject
noplain
