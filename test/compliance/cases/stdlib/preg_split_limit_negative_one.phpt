--TEST--
stdlib preg_split() limit -1 with PREG_SPLIT_DELIM_CAPTURE (#13423, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);

function check(string $label, mixed $got, mixed $expected): void
{
    if ($got !== $expected) {
        echo "FAIL $label\n";
        var_export($got);
        echo "\n";
    }
}

check('preg_split(-1)', preg_split('/ /', 'a b c', -1), ['a', 'b', 'c']);
check(
    'preg_split(-1 delim)',
    preg_split('/( )/', 'a b c', -1, PREG_SPLIT_DELIM_CAPTURE),
    ['a', ' ', 'b', ' ', 'c']
);
check('preg_split(-1 /a/)', preg_split('/a/', 'bab', -1), ['b', 'b']);
echo "ok\n";
--EXPECT--
ok
