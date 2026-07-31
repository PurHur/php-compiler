<?php
// #25799 — SplFileObject / SplTempFileObject class_implements Traversable/Iterator order
echo 'SplFileObject:', implode(',', class_implements('SplFileObject')), "\n";
echo 'SplTempFileObject:', implode(',', class_implements('SplTempFileObject')), "\n";
$r = new ReflectionClass('SplFileObject');
echo 'SplFileObject refl:', implode(',', $r->getInterfaceNames()), "\n";
$r = new ReflectionClass('SplTempFileObject');
echo 'SplTempFileObject refl:', implode(',', $r->getInterfaceNames()), "\n";
