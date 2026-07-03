<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hello');
fseek($fp, 0, SEEK_END);
echo 'eof=', ftell($fp), "\n";
fseek($fp, -2, SEEK_CUR);
echo 'tail=', fread($fp, 10), "\n";
$whence = constant('SEEK_END');
fseek($fp, 0, $whence);
echo 'const_eof=', ftell($fp), "\n";
