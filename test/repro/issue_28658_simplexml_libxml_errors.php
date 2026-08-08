<?php
/** Repro #28658 — simplexml_load_string malformed → libxml_get_errors count 2 (76+77). */
libxml_use_internal_errors(true);
libxml_clear_errors();
$d = simplexml_load_string('<a><b></a>');
echo $d === false ? "false\n" : "notfalse\n";
$errs = libxml_get_errors();
echo 'count=', count($errs), "\n";
foreach ($errs as $e) {
    echo 'line=', $e->line, ' code=', $e->code, ' msg=', trim($e->message), "\n";
}
