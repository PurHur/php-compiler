--TEST--
stdlib move_uploaded_file() after multipart $_FILES (issue #2005)
--ENV--
REQUEST_METHOD=POST
CONTENT_TYPE=multipart/form-data; boundary=phpcMoveUpB
--POST--
--phpcMoveUpB
Content-Disposition: form-data; name="doc"; filename="photo.txt"
Content-Type: text/plain

filedata
--phpcMoveUpB--
--FILE--
<?php
declare(strict_types=1);
$base = 'test/compliance/cases/stdlib/move_uploaded_file_fixture';
$dest = $base . '/multipart_saved.txt';
@unlink($dest);
$tmp = $_FILES['doc']['tmp_name'];
if (move_uploaded_file($tmp, $dest)) {
    echo "ok\n";
    $size = filesize($dest);
    echo 'size:', false === $size ? 'fail' : (string) $size, "\n";
} else {
    echo "fail\n";
}
@unlink($dest);
--EXPECT--
ok
size:8
