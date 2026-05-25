--TEST--
stdlib move_uploaded_file()
--FILE--
<?php
$tmp = tempnam(sys_get_temp_dir(), 'phpc_upload_');
file_put_contents($tmp, 'payload');
$dest = tempnam(sys_get_temp_dir(), 'phpc_saved_');
@unlink($dest);
if (move_uploaded_file($tmp, $dest)) {
    echo "ok\n";
} else {
    echo "fail\n";
}
if (is_file($tmp)) {
    echo "src\n";
} else {
    echo "gone\n";
}
if (is_file($dest)) {
    echo "dest\n";
} else {
    echo "nodest\n";
}
$other = tempnam(sys_get_temp_dir(), 'other_');
file_put_contents($other, 'x');
if (move_uploaded_file($other, $dest)) {
    echo "bad_ok\n";
} else {
    echo "rej\n";
}
@unlink($other);
if (move_uploaded_file($dest, tempnam(sys_get_temp_dir(), 'phpc_nope_'))) {
    echo "bad_src\n";
} else {
    echo "rej_src\n";
}
@unlink($dest);
if (move_uploaded_file('phpc_upload_fake', sys_get_temp_dir() . '/../outside.txt')) {
    echo "traversal_ok\n";
} else {
    echo "rej_dot\n";
}
--EXPECT--
ok
gone
dest
rej
rej_src
rej_dot
