<?php

declare(strict_types=1);

$data = pack('n', 1);
$result = unpack(format: 'n', string: $data);
echo 'unpack_named_ok=', is_array($result) && isset($result[1]) && 1 === $result[1] ? '1' : '0', "\n";
