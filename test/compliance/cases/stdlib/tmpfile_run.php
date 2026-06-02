<?php

$h = tmpfile();
$w = fwrite($h, 'hello');
rewind($h);
$data = fread($h, 5);
fclose($h);
echo 'hello' === $data ? "ok\n" : "fail\n";
