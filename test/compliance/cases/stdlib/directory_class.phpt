--TEST--
stdlib Directory class + dir() (ext/standard/dir.c, #13368)
--FILE--
<?php
echo class_exists('Directory', false) ? 'yes' : 'no', "\n";
$d = dir('.');
echo ($d instanceof Directory) ? 'instance' : 'not-instance', "\n";
echo $d->path, "\n";
$first = $d->read();
echo is_string($first) ? 'read-ok' : 'read-fail', "\n";
$d->rewind();
$d->close();
try {
    new Directory();
    echo "constructed\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
yes
instance
.
read-ok
Cannot directly construct Directory, use dir() instead
