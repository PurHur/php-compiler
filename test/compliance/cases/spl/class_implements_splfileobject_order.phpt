--TEST--
class_implements()/Reflection SplFileObject Traversable/Iterator order (#25799, ext/spl/spl_directory.c)
--FILE--
<?php
echo 'SplFileObject:', implode(',', class_implements('SplFileObject')), "\n";
echo 'SplTempFileObject:', implode(',', class_implements('SplTempFileObject')), "\n";
$r = new ReflectionClass('SplFileObject');
echo 'SplFileObject refl:', implode(',', $r->getInterfaceNames()), "\n";
$r = new ReflectionClass('SplTempFileObject');
echo 'SplTempFileObject refl:', implode(',', $r->getInterfaceNames()), "\n";
?>
--EXPECT--
SplFileObject:Stringable,RecursiveIterator,Traversable,Iterator,SeekableIterator
SplTempFileObject:SeekableIterator,Iterator,Traversable,RecursiveIterator,Stringable
SplFileObject refl:Stringable,RecursiveIterator,Traversable,Iterator,SeekableIterator
SplTempFileObject refl:SeekableIterator,Iterator,Traversable,RecursiveIterator,Stringable
