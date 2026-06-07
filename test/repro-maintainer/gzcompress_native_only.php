<?php
// Issue #6476: VM gz* must not depend on host ext-zlib.
$z = gzcompress('hello');
var_dump(is_string($z), strlen($z));
echo gzuncompress($z) === 'hello' ? "ok\n" : "fail\n";
