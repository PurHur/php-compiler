<?php
// AOT: new SplFileInfo($path) must initialise __dir_path / __filename (#33290)
$paths = [
    __DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture/a.txt',
    '/etc/passwd',
    '/etc',
    'rel',
    '/',
    '/a/',
    'foo/bar',
];
foreach ($paths as $p) {
    $f = new SplFileInfo($p);
    echo 'p=', json_encode($p),
        ' path=', json_encode($f->getPath()),
        ' fn=', json_encode($f->getFilename()),
        ' pn=', json_encode($f->getPathname()),
        "\n";
}
