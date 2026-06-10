<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

$data = @pack('c', E::A);
$r = unpack('c', $data);
echo 'ok ', $r[1], "\n";
