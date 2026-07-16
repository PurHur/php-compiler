--TEST--
DirectoryIterator __toString yields entry basename (#19482, ext/spl/spl_directory.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_diriter_tostring_' . getmypid();
@mkdir($dir);
$p = $dir . '/entry.txt';
file_put_contents($p, 'x');

$d = new DirectoryIterator($dir);
$d->rewind();
while ($d->valid() && $d->isDot()) {
    $d->next();
}
echo ((string) $d === $d->getFilename()) ? "basename\n" : "mismatch\n";
echo ((string) $d === 'entry.txt') ? "entry\n" : "not-entry\n";
echo ($d->getPathname() !== (string) $d) ? "pathname\n" : "same\n";

@unlink($p);
@rmdir($dir);
?>
--EXPECT--
basename
entry
pathname
