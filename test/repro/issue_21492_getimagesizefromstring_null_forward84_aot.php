<?php
/**
 * AOT return-value probe for #21492 — soft-null → false (avoid var_export / set_error_handler).
 * Run: PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=1 ./script/docker-exec.sh -- bash -lc \
 *   './phpc build -o /tmp/gis21492 test/repro/issue_21492_getimagesizefromstring_null_forward84_aot.php && /tmp/gis21492'
 */
$r = @getimagesizefromstring(null);
echo $r === false ? "false\n" : "other\n";
