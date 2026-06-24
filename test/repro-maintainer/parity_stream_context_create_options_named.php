<?php

/** Issue #11114 — stream_context_create(options: [...]) named first parameter. */

$ctx = stream_context_create(options: ['http' => ['timeout' => 1]]);
var_export(is_resource($ctx));
echo "\n";
