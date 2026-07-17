<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/phpc_sfi_info_' . getmypid();
file_put_contents($tmp, 'hello');
$i = new SplFileInfo($tmp);
foreach (['getFileInfo', 'getPathInfo', 'openFile', 'setFileClass', 'setInfoClass'] as $m) {
    echo $m, ' method_exists=', (int) method_exists($i, $m), "\n";
}

$fi = $i->getFileInfo();
echo 'getFileInfo_class=', get_class($fi), ' path=', $fi->getPathname(), "\n";
$pi = $i->getPathInfo();
echo 'getPathInfo_class=', get_class($pi), ' path=', $pi->getPathname(), "\n";

class MyInfo extends SplFileInfo {}
class MyFile extends SplFileObject {}
$i->setInfoClass(MyInfo::class);
$i->setFileClass(MyFile::class);
echo 'after_setInfo=', get_class($i->getFileInfo()), "\n";
$fo = $i->openFile('r');
echo 'openFile_class=', get_class($fo), ' read=', $fo->fread(5), "\n";

$i->setInfoClass();
$i->setFileClass();
echo 'reset_info=', get_class($i->getFileInfo()), ' reset_file=', get_class($i->openFile()), "\n";

try {
    $i->setInfoClass('stdClass');
    echo "bad_info_ok\n";
} catch (Throwable $e) {
    echo 'bad_info=', get_class($e), "\n";
}

@unlink($tmp);
