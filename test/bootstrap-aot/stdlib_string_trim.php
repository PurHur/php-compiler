<?php
declare(strict_types=1);
$s = '  ' . trim(' hello ') . '  ';
echo strlen(trim($s)) >= 5 ? '1' : '0';
echo "\n";
