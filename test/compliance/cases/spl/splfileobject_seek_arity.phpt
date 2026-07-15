--TEST--
SPL SplFileObject::seek() single-arg line seek — reject SEEK_* second arg (#18304, ext/spl/spl_directory.c)
--FILE--
<?php
declare(strict_types=1);

$f = new SplFileObject('php://memory');
$f->fwrite("a\nb\nc");
$f->rewind();
try {
    $f->seek(1, SEEK_END);
    echo "seek2=ok\n";
} catch (ArgumentCountError $e) {
    echo "seek2=ArgumentCountError\n";
}
$f->rewind();
$f->seek(1);
echo 'key='.$f->key()."\n";
?>
--EXPECT--
seek2=ArgumentCountError
key=1
