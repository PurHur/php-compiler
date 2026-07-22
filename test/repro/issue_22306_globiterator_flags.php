<?php
// repro #22306 GlobIterator getFlags / foreach
$dir = __DIR__;
$gi = new GlobIterator($dir.'/*.php');
echo 'flags=', $gi->getFlags(), "\n";
$n = 0;
$isInfo = 'N';
foreach ($gi as $info) {
    $isInfo = ($info instanceof SplFileInfo) ? 'Y' : 'N';
    ++$n;
    if ($n >= 1) {
        break;
    }
}
echo 'foreach_is_info=', $isInfo, "\n";
echo 'foreach_n=', $n, "\n";
echo 'count=', (new GlobIterator($dir.'/issue_22286*.php'))->count(), "\n";
$gi->setFlags(FilesystemIterator::CURRENT_AS_PATHNAME);
$gi->rewind();
echo 'flags2=', $gi->getFlags(), "\n";
foreach ($gi as $path) {
    echo 'pathmode=', is_string($path) ? 'Y' : 'N', "\n";
    break;
}
