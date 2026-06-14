<?php

$h = fopen('php://memory', 'r+');
fwrite($h, 'data');
rewind($h);
var_dump(fread($h, 10));
rewind($h);
var_dump(stream_get_line($h, 10));
rewind($h);
var_dump(fgets($h));
