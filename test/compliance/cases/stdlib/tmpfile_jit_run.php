<?php

$h = tmpfile();
$w = fwrite($h, 'jit');
rewind($h);
$data = fread($h, 3);
fclose($h);
echo 'jit' === $data ? "ok\n" : "fail\n";
