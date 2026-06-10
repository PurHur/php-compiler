<?php
$f = tmpfile();
echo stream_set_blocking($f, false) ? "ok\n" : "fail\n";
fclose($f);
