<?php
// Compile-only (#6007): stream_get_meta_data() / stream_set_blocking() JIT/AOT lowering.
declare(strict_types=1);
$f = tmpfile();
$meta = stream_get_meta_data($f);
stream_set_blocking($f, false);
stream_set_blocking($f, true);
fclose($f);
echo is_array($meta) ? "ok\n" : "fail\n";
