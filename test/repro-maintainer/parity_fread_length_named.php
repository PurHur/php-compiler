<?php

/** Issue #11111 — fread($fp, length: 3) named second parameter. */

$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hello');
rewind($fp);
echo fread($fp, length: 3), "\n";
fclose($fp);
