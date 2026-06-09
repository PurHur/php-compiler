<?php
$packed = pack('d', 1.5);
$r = unpack('d', $packed);
var_dump($r);
echo gettype($r[1]), "\n";
