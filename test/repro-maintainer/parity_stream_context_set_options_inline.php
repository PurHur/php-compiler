<?php
declare(strict_types=1);
$ctx = stream_context_create();
stream_context_set_options($ctx, ['http' => ['timeout' => 1]]);
$got = stream_context_get_options($ctx);
echo 'timeout=', ($got['http']['timeout'] ?? 'missing'), PHP_EOL;
