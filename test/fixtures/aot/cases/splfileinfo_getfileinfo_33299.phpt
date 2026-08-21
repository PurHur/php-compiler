--TEST--
AOT: SplFileInfo getFileInfo/getPathInfo (#33299)
--FILE--
<?php
$f = new SplFileInfo('/etc/passwd');
$fi = $f->getFileInfo();
echo 'fi=', get_class($fi), ' pn=', $fi->getPathname(), ' fn=', $fi->getFilename(), "\n";
$pi = $f->getPathInfo();
echo 'pi=', get_class($pi),
    ' path=', json_encode($pi->getPath()),
    ' fn=', json_encode($pi->getFilename()),
    ' pn=', json_encode($pi->getPathname()),
    "\n";
$seg = new SplFileInfo('/etc');
echo 'seg path=', json_encode($seg->getPath()), ' fn=', json_encode($seg->getFilename()), "\n";
--EXPECT--
fi=SplFileInfo pn=/etc/passwd fn=passwd
pi=SplFileInfo path="" fn="\/etc" pn="\/etc"
seg path="" fn="\/etc"
