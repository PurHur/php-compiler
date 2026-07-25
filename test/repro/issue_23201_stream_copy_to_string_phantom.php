<?php
/** Repro #23201 — stream_copy_to_string phantom: not in php-src ext/standard/streamsfuncs.c. */
echo function_exists('stream_copy_to_string') ? "fail\n" : "ok\n";
