--TEST--
SPL RecursiveDirectoryIterator::hasChildren false for . and .. (#24291, ext/spl/spl_directory.c)
--FILE--
<?php
$td = sys_get_temp_dir() . '/phpc_rdi_dot_' . getmypid();
@mkdir($td);
@mkdir($td . '/sub');
file_put_contents($td . '/f.txt', 'x');

$it = new RecursiveDirectoryIterator($td, FilesystemIterator::CURRENT_AS_SELF);
$seen = [];
foreach ($it as $entry) {
    $seen[$entry->getFilename()] = $entry->hasChildren();
}
ksort($seen);
foreach ($seen as $name => $has) {
    echo $name, '=', $has ? '1' : '0', "\n";
}

@unlink($td . '/f.txt');
@rmdir($td . '/sub');
@rmdir($td);
?>
--EXPECT--
.=0
..=0
f.txt=0
sub=1
