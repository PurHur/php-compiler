<?php

declare(strict_types=1);

$it = new FilesystemIterator(sys_get_temp_dir());
$it->rewind();
if (!$it->valid()) {
    echo "ok\n";
    exit(0);
}
echo 'fail valid=yes key='.var_export($it->key(), true)."\n";
