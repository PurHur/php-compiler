<?php
$h = fopen('php://memory', 'r+');
fseek($h, 99);
var_dump(ftell($h));
