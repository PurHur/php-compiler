<?php
// AOT: SplFileInfo getFileInfo/getPathInfo + absolute-path construct parity (#33299)
$paths = ['/etc/passwd', '/etc', 'foo/bar', '/'];
foreach ($paths as $p) {
    $f = new SplFileInfo($p);
    echo 'p=', json_encode($p),
        ' path=', json_encode($f->getPath()),
        ' fn=', json_encode($f->getFilename()),
        ' pn=', json_encode($f->getPathname()),
        "\n";
    $fi = $f->getFileInfo();
    echo '  fileinfo class=', get_class($fi),
        ' pn=', json_encode($fi->getPathname()),
        ' fn=', json_encode($fi->getFilename()),
        "\n";
    $pi = $f->getPathInfo();
    if (null === $pi) {
        echo "  pathinfo=null\n";
    } else {
        echo '  pathinfo class=', get_class($pi),
            ' path=', json_encode($pi->getPath()),
            ' fn=', json_encode($pi->getFilename()),
            ' pn=', json_encode($pi->getPathname()),
            "\n";
    }
}
