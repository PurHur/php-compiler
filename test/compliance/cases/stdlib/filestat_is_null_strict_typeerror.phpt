--TEST--
stdlib is_* filestat — null filename TypeError under strict_types (#14620, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);
$errors = 0;
foreach (['is_readable', 'is_writable', 'is_executable', 'is_dir', 'is_link'] as $fn) {
    try {
        $fn(null);
        ++$errors;
    } catch (TypeError) {
    }
}
echo 0 === $errors ? "ok\n" : "fail\n";
--EXPECT--
ok
