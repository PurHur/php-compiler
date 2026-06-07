<?php
var_dump(function_exists('fdatasync'));
$fp = fopen('php://memory', 'w+');
fwrite($fp, 'x');
var_export(fdatasync($fp));
echo "\n";
fclose($fp);
