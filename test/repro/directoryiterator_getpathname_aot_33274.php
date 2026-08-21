<?php
/**
 * #33274 — AOT DirectoryIterator getPathname/getPath/__toString must match Zend
 * (no object::getpathname abort). Peer of #33263/#33269.
 *
 * DirectoryIterator::__toString is basename (#19482); getPathname is joined path.
 * Use explicit __toString() — bare (string)$foreachVar can lack a class hint for cast.
 */
$dir = __DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    if (!$f->isFile()) {
        continue;
    }
    $name = $f->getFilename();
    $path = $f->getPath();
    $pathname = $f->getPathname();
    $asString = $f->__toString();
    echo 'name=', $name,
        ' path=', $path,
        ' pathname=', $pathname,
        ' str=', $asString,
        ' str_is_name=', ($asString === $name) ? '1' : '0',
        ' pathname_ends=', (str_ends_with($pathname, '/'.$name) || str_ends_with($pathname, $name)) ? '1' : '0',
        "\n";
}
