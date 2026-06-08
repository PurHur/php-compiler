<?php
$f = fopen('php://memory', 'r+');
fwrite($f, 'abc');
fseek($f, -1, SEEK_END);
var_dump(ftell($f), fgetc($f));
