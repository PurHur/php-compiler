--TEST--
stdlib path/filestat builtins — null path TypeError under strict_types JIT (#13354, #17161, ext/standard/filestat.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
$fail = 0;
foreach (['file_exists', 'is_file', 'is_dir', 'filesize'] as $fn) {
    try {
        $fn(null);
        ++$fail;
    } catch (TypeError) {
    }
}
try {
    rename(null, '/tmp/no-such-target-13354');
    ++$fail;
} catch (TypeError) {
}
try {
    pathinfo(null);
    ++$fail;
} catch (TypeError) {
}
try {
    basename(null);
    ++$fail;
} catch (TypeError) {
}
try {
    dirname(null);
    ++$fail;
} catch (TypeError) {
}
echo 0 === $fail ? "ok\n" : "fail\n";
--EXPECT--
ok
