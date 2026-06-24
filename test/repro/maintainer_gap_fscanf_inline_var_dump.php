<?php

$f = tmpfile();
fwrite($f, '42 answer');
rewind($f);
var_dump(fscanf($f, '%d %s'));
