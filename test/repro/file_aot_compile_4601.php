<?php
$tmp = tempnam(sys_get_temp_dir(), 'f');
file_put_contents($tmp, "a\nb\n");
$lines = file($tmp, "2");
echo count($lines), "\n";
unlink($tmp);
