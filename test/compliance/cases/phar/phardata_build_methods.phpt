--TEST--
stdlib PharData archive build/compress methods (#19893, ext/phar/phar_object.c)
--FILE--
<?php
foreach (['addFromString', 'compress', 'decompress', 'convertToExecutable', 'convertToData', 'addEmptyDir', 'buildFromDirectory', 'buildFromIterator', 'addFile'] as $m) {
    echo $m . '=' . (method_exists('PharData', $m) ? '1' : '0') . "\n";
}
$tmp = sys_get_temp_dir() . '/phardata_methods_' . getmypid() . '.tar';
@unlink($tmp);
$p = new PharData($tmp);
$p->addFromString('a.txt', 'hi');
$p->addEmptyDir('d');
echo 'exists_d=' . ($p->offsetExists('d') ? '1' : '0') . "\n";
echo 'content=' . $p['a.txt']->getContent() . "\n";
@unlink($tmp);
?>
--EXPECT--
addFromString=1
compress=1
decompress=1
convertToExecutable=1
convertToData=1
addEmptyDir=1
buildFromDirectory=1
buildFromIterator=1
addFile=1
exists_d=1
content=hi
