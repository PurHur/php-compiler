--TEST--
DirectoryIterator::isDot() and getType() (issue #13158, ext/spl/spl_directory.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_diriter_isdot_' . getmypid();
mkdir($dir);
file_put_contents($dir . '/sample.txt', 'x');

$dots = 0;
$sampleType = '';
foreach (new DirectoryIterator($dir) as $entry) {
    if ($entry->isDot()) {
        ++$dots;
        continue;
    }
    if ('sample.txt' === $entry->getFilename()) {
        $sampleType = $entry->getType();
    }
}

@unlink($dir . '/sample.txt');
@rmdir($dir);

echo $dots, "\n";
echo $sampleType, "\n";
?>
--EXPECT--
2
file
