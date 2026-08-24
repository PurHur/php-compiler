<?php
$f = tmpfile();
fwrite($f, "ab");
rewind($f);
echo fread($f, 1);
echo feof($f) ? '0' : 'n';
echo fread($f, 1);
echo feof($f) ? '0' : 'n';
echo fread($f, 1);
echo feof($f) ? '1' : 'n';
fclose($f);
echo "DONE\n";
