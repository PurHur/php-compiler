<?php

/**
 * Repro #31422: stream_context_set_option($ctx, null, …) soft-null DEP + true.
 */

$c = stream_context_create();
$r = stream_context_set_option($c, null, 'a', 'b');
var_export($r);
echo "\n";
