<?php

declare(strict_types=1);

$path = __DIR__.'/file_get_contents_offset.php';
echo file_get_contents($path, false, null, 0, 4), "\n";
echo file_get_contents($path, false, null, 5, 3), "\n";
