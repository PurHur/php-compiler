<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/phpc_sfi_20090_' . getmypid();
file_put_contents($tmp, 'hello');
$i = new SplFileInfo($tmp);

echo (int) method_exists($i, 'getFileInfo'),
    (int) method_exists($i, 'getPathInfo'),
    (int) method_exists($i, 'openFile'),
    (int) method_exists($i, 'setFileClass'),
    (int) method_exists($i, 'setInfoClass'),
    "\n";

$fi = $i->getFileInfo();
echo get_class($fi), ' ', $fi->getPathname() === $tmp ? 'path-ok' : 'path-bad', "\n";
$pi = $i->getPathInfo();
echo get_class($pi), ' ', $pi->getPathname() === dirname($tmp) ? 'dir-ok' : 'dir-bad', "\n";

class MyInfo20090 extends SplFileInfo
{
}
class MyFile20090 extends SplFileObject
{
}
$i->setInfoClass(MyInfo20090::class);
$i->setFileClass(MyFile20090::class);
$fi2 = $i->getFileInfo();
echo get_class($fi2), "\n";
$fo = $i->openFile('r');
echo get_class($fo), ' ', $fo->fread(5), "\n";

$i->setInfoClass();
$i->setFileClass();
$fi3 = $i->getFileInfo();
$fo2 = $i->openFile();
echo get_class($fi3), ' ', get_class($fo2), "\n";

try {
    $i->setInfoClass(stdClass::class);
    echo "bad-info-ok\n";
} catch (TypeError $e) {
    echo "bad-info-typeerror\n";
}

@unlink($tmp);
