<?php

/**
 * Issue #27295 — thin AOT stream_context_set_option four-arg + get_options.
 *
 * Zend / VM / JIT / AOT must print true then POST.
 */
$ctx = stream_context_create();
$ok = stream_context_set_option($ctx, 'http', 'method', 'POST');
var_export($ok);
echo "\n";
echo stream_context_get_options($ctx)['http']['method'], "\n";
