--TEST--
AOT: md5_file() / sha1_file() (issue #3590)
--FILE--
<?php
$abc = 'test/compliance/cases/stdlib/md5_file_fixture/abc.txt';
echo md5_file($abc), "\n";
echo sha1_file($abc), "\n";
set_error_handler(static fn () => true);
if (md5_file('/no/such/phpc-md5-file-aot') === false) {
    echo 'gone', "\n";
}
restore_error_handler();
--EXPECT--
900150983cd24fb0d6963f7d28e17f72
a9993e364706816aba3e25717850c26c9cd0d89d
gone
