<?php

declare(strict_types=1);

$algos = hash_algos();
echo 'listed=', var_export(in_array('crc32c', $algos, true), true), "\n";
echo hash('crc32c', 'test'), "\n";
