--TEST--
SPL SplFileInfo absolute single-segment getPath/getFilename (#24338, ext/spl/spl_directory.c)
--FILE--
<?php
foreach (['/etc', '/tmp', '/', '/a/'] as $p) {
    $fi = new SplFileInfo($p);
    echo $p,
        ' path=', json_encode($fi->getPath()),
        ' fn=', json_encode($fi->getFilename()),
        ' bn=', json_encode($fi->getBasename()),
        ' pn=', json_encode($fi->getPathname()),
        "\n";
}
$pi = (new SplFileInfo('/etc/passwd'))->getPathInfo();
echo 'pathinfo path=', json_encode($pi->getPath()),
    ' fn=', json_encode($pi->getFilename()),
    ' pn=', json_encode($pi->getPathname()),
    "\n";
$rel = new SplFileInfo('rel');
echo 'rel path=', json_encode($rel->getPath()),
    ' fn=', json_encode($rel->getFilename()),
    "\n";
--EXPECT--
/etc path="" fn="\/etc" bn="etc" pn="\/etc"
/tmp path="" fn="\/tmp" bn="tmp" pn="\/tmp"
/ path="" fn="\/" bn="" pn="\/"
/a/ path="" fn="\/a" bn="a" pn="\/a"
pathinfo path="" fn="\/etc" pn="\/etc"
rel path="" fn="rel"
