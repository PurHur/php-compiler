<?php

declare(strict_types=1);

$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
$h = opendir($dir);
$first = readdir($h);
closedir($h);

if ('.' === $first || '..' === $first) {
    echo "first={$first}\n";
    exit(1);
}

echo "ok first={$first}\n";
