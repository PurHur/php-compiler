<?php
declare(strict_types=1);

$a = ['k' => 'v'];
echo ($a['k'] ?? 'x'), "\n";
echo 'file=', ($a['file'] ?? 'missing'), "\n";
