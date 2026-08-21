--TEST--
AOT: SplFileInfo absolute single-segment construct (#33304)
--FILE--
<?php
foreach (['/etc', '/', '/a/'] as $p) {
    $f = new SplFileInfo($p);
    echo $p, ' path=', json_encode($f->getPath()), ' fn=', json_encode($f->getFilename()), "\n";
}
$pi = (new SplFileInfo('/etc/passwd'))->getPathInfo();
echo 'pi path=', json_encode($pi->getPath()), ' fn=', json_encode($pi->getFilename()), "\n";
--EXPECT--
/etc path="" fn="\/etc"
/ path="" fn="\/"
/a/ path="" fn="\/a"
pi path="" fn="\/etc"
