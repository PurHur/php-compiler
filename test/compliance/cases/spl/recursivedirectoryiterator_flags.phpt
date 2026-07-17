--TEST--
SPL RecursiveDirectoryIterator default flags=0 and SKIP_DOTS listing (#20145, ext/spl/spl_directory.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/rdi_flags_' . getmypid() . '_' . str_replace('.', '_', uniqid('', true));
mkdir($dir);
file_put_contents($dir . '/x.txt', 'x');
mkdir($dir . '/sub');

echo 'default_flags=', (new RecursiveDirectoryIterator($dir))->getFlags(), "\n";
echo 'fi_default_flags=', (new FilesystemIterator($dir))->getFlags(), "\n";

$names = [];
foreach (new RecursiveDirectoryIterator($dir) as $v) {
    $names[] = is_object($v) ? $v->getFilename() : (string) $v;
}
sort($names);
echo 'default_entries=', implode(',', $names), "\n";

$names2 = [];
foreach (new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS) as $v) {
    $names2[] = is_object($v) ? $v->getFilename() : (string) $v;
}
sort($names2);
echo 'skip_dots_entries=', implode(',', $names2), "\n";
?>
--EXPECT--
default_flags=0
fi_default_flags=4096
default_entries=.,..,sub,x.txt
skip_dots_entries=sub,x.txt
