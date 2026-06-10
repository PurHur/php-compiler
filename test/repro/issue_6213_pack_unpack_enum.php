<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

$data = pack('c', E::A);
var_dump(unpack('c', $data));
