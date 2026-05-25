--TEST--
stdlib move_uploaded_file() (issue #2005)
--FILE--
<?php
declare(strict_types=1);
$base = 'test/compliance/cases/stdlib/move_uploaded_file_fixture';
$dest = $base . '/saved.txt';
@unlink($dest);

$tmp = tempnam(sys_get_temp_dir(), 'phpc_upload_');
if (false === $tmp) {
    echo "notmp\n";
    exit(1);
}
@unlink($tmp);
if (false === file_put_contents($tmp, 'upload-bytes')) {
    echo "nowrite\n";
    exit(1);
}

if (move_uploaded_file($tmp, $dest)) {
    echo "ok\n";
} else {
    echo "fail\n";
}
if (is_file($tmp)) {
    echo "left\n";
} else {
    echo "gone\n";
}
$size = filesize($dest);
echo 'size:', false === $size ? 'fail' : (string) $size, "\n";

$bogus = $base . '/from.txt';
file_put_contents($bogus, 'x');
if (move_uploaded_file($bogus, $dest . '.2')) {
    echo "bad\n";
} else {
    echo "reject\n";
}
@unlink($bogus);
@unlink($dest);
--EXPECT--
ok
gone
size:12
reject
