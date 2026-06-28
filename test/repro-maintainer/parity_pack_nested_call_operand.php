<?php
declare(strict_types=1);

$p = pack('C', 0);
echo 'assign_len=', strlen($p), "\n";

echo 'nested_len=', strlen(pack('C', 0)), "\n";

echo 'expr_len=', strlen(($q = pack('C', 0))), "\n";

echo 'bin2hex_nested=', bin2hex(pack('C', 255)), "\n";
