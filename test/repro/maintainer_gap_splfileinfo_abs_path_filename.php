<?php
foreach (['/etc', '/tmp', '/', '/a/'] as $p) {
    $fi = new SplFileInfo($p);
    echo $p,
        ' path=', json_encode($fi->getPath()),
        ' fn=', json_encode($fi->getFilename()),
        ' bn=', json_encode($fi->getBasename()),
        "\n";
}
$pi = (new SplFileInfo('/etc/passwd'))->getPathInfo();
echo 'pathinfo fn=', json_encode($pi->getFilename()),
    ' pn=', json_encode($pi->getPathname()),
    "\n";
