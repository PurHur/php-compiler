<?php

declare(strict_types=1);

$ctx = stream_context_create(['http' => ['timeout' => 5]]);
$opts = stream_context_get_options($ctx);
$ok = isset($opts['http']['timeout']) && 5 === $opts['http']['timeout'];
echo $ok ? "stream_context_create_nested_ok=1\n" : "stream_context_create_nested_ok=0\n";
