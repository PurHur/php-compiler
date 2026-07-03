<?php

declare(strict_types=1);

$tmpdir = sys_get_temp_dir().'/php_compiler_glob_dotfiles_'.getmypid();
if (!@mkdir($tmpdir) && !is_dir($tmpdir)) {
    echo "fail: mkdir\n";
    exit(1);
}

file_put_contents($tmpdir.'/visible.txt', 'x');
file_put_contents($tmpdir.'/.hidden', 'y');

$wildcard = glob($tmpdir.'/*');
if (false === $wildcard) {
    echo "fail: glob wildcard returned false\n";
    exit(1);
}
$wildcard = array_map('basename', $wildcard);
sort($wildcard);
if ($wildcard !== ['visible.txt']) {
    echo 'fail: glob wildcard got ', var_export($wildcard, true), "\n";
    exit(1);
}

$explicit = glob($tmpdir.'/.hidden');
if (false === $explicit || [] === $explicit) {
    echo "fail: glob explicit dot pattern did not match\n";
    exit(1);
}

@unlink($tmpdir.'/visible.txt');
@unlink($tmpdir.'/.hidden');
@rmdir($tmpdir);

echo "ok\n";
