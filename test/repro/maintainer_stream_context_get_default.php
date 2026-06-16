<?php

declare(strict_types=1);

$ctx = stream_context_get_default();
echo is_resource($ctx) ? "resource\n" : "not_resource\n";
echo get_debug_type($ctx), "\n";
echo gettype($ctx), "\n";
echo get_resource_type($ctx), "\n";

stream_context_set_default(['http' => ['timeout' => 5]]);
$opts = stream_context_get_options(stream_context_get_default());
echo $opts['http']['timeout'], "\n";
