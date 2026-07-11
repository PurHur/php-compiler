<?php

declare(strict_types=1);

$s = 'hello';
var_dump(strlen($s), substr($s, 0, 2));

$f = fopen('php://memory', 'r+');
fwrite($f, 'abc');
fseek($f, -1, SEEK_END);
var_dump(ftell($f), fgetc($f));
