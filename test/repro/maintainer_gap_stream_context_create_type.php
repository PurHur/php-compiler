<?php

declare(strict_types=1);

$ctx = stream_context_create(['http' => ['timeout' => 5]]);
echo is_array($ctx) || is_resource($ctx) ? "ok\n" : "bad\n";

foreach ([new stdClass(), 'not-array', 1] as $bad) {
    try {
        stream_context_create($bad);
        echo "no throw\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
    }
}
