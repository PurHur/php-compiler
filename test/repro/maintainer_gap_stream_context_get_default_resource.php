<?php

declare(strict_types=1);

stream_context_set_default(['http' => ['timeout' => 5]]);
$ctx = stream_context_get_default();
if (!\is_resource($ctx)) {
    fwrite(STDERR, "fail: expected resource, got ".gettype($ctx)."\n");
    exit(1);
}
if ('stream-context' !== get_resource_type($ctx)) {
    fwrite(STDERR, "fail: expected stream-context type\n");
    exit(1);
}
ob_start();
var_dump($ctx);
$dump = ob_get_clean();
if (!str_starts_with($dump, 'resource(')) {
    fwrite(STDERR, "fail: var_dump must show resource, got: {$dump}\n");
    exit(1);
}
echo "ok\n";
