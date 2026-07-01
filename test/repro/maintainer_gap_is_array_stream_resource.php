<?php

declare(strict_types=1);

$ctx = stream_context_get_default();
if (!\is_resource($ctx)) {
    fwrite(STDERR, 'fail: expected resource, got '.gettype($ctx)."\n");
    exit(1);
}
if (\is_array($ctx)) {
    fwrite(STDERR, "fail: is_array(stream_context_get_default()) must be false\n");
    exit(1);
}
if (!\is_array([])) {
    fwrite(STDERR, "fail: is_array([]) must be true\n");
    exit(1);
}
echo "ok\n";
