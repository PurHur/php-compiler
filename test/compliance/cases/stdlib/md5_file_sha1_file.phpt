--TEST--
stdlib md5_file() / sha1_file() — file digest (issue #3590)
--FILE--
<?php
$abc = 'test/compliance/cases/stdlib/md5_file_fixture/abc.txt';
$empty = 'test/compliance/cases/stdlib/md5_file_fixture/empty.txt';
echo function_exists('md5_file') ? '1' : '0', "\n";
echo function_exists('sha1_file') ? '1' : '0', "\n";
echo md5_file($abc), "\n";
echo sha1_file($abc), "\n";
echo md5_file($empty), "\n";
set_error_handler(static fn () => true);
if (md5_file('/no/such/phpc-md5-file-path') === false) {
    echo 'gone', "\n";
} else {
    echo 'bad', "\n";
}
restore_error_handler();
--EXPECT--
1
1
900150983cd24fb0d6963f7d28e17f72
a9993e364706816aba3e25717850c26c9cd0d89d
d41d8cd98f00b204e9800998ecf8427e
gone
