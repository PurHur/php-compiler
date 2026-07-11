<?php

declare(strict_types=1);

$dir = sys_get_temp_dir();
$it = new FilesystemIterator($dir);
if (!method_exists($it, 'getFlags') || !method_exists($it, 'setFlags')) {
    fwrite(STDERR, "fail: getFlags()/setFlags() missing\n");
    exit(1);
}

$base = $it->getFlags();
$it->setFlags($base | FilesystemIterator::KEY_AS_FILENAME);
if (0 === ($it->getFlags() & FilesystemIterator::KEY_AS_FILENAME)) {
    fwrite(STDERR, "fail: KEY_AS_FILENAME not set after setFlags()\n");
    exit(1);
}

echo "ok\n";
