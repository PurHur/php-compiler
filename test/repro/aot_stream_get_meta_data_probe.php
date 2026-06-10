<?php
$f = tmpfile();
$meta = stream_get_meta_data($f);
echo is_array($meta) && isset($meta['seekable']) ? "ok\n" : "fail\n";
fclose($f);
