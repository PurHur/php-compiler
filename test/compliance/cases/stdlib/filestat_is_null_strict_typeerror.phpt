--TEST--
stdlib is_* / file_exists filestat — null filename TypeError under strict_types (#13354, #17161, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);
$fail = 0;
foreach (['is_readable', 'is_writable', 'is_executable', 'is_dir', 'is_link', 'is_file', 'file_exists', 'filesize', 'is_uploaded_file'] as $fn) {
    try {
        $fn(null);
        ++$fail;
    } catch (TypeError) {
    }
}
echo 0 === $fail ? "ok\n" : "fail\n";
--EXPECT--
ok
