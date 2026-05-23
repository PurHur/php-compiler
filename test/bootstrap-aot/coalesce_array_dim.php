<?php

declare(strict_types=1);

$a = ['k' => 'present'];
echo $a['missing'] ?? 'default';
echo "\n";
echo ($a['k'] ?? 'none') === 'present' ? '1' : '0';
echo "\n";
