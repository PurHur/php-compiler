--TEST--
stdlib opendir()/mkdir()/rmdir()/chdir() null under strict_types — TypeError (#18673, #12664)
--FILE--
<?php
declare(strict_types=1);
$fail = 0;
foreach (['opendir', 'mkdir', 'rmdir', 'chdir'] as $fn) {
    try {
        $fn(null);
        ++$fail;
    } catch (\TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be of type string')) {
            ++$fail;
        }
    }
}
echo 0 === $fail ? "ok\n" : "fail\n";
--EXPECT--
ok
