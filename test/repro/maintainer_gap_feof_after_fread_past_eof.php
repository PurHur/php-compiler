<?php

$h = fopen('php://memory', 'r+');
fwrite($h, 'hello');
rewind($h);
fread($h, 9999);
if (!feof($h)) {
    echo "fail: feof() false after fread past EOF\n";
    exit(1);
}
fclose($h);
echo "ok\n";
