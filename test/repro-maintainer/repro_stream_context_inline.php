<?php

try {
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    echo "inline:ok\n";
} catch (Throwable $e) {
    echo "inline:", get_class($e), "\n";
}

$a = ['http' => ['timeout' => 5]];
try {
    $ctx2 = stream_context_create($a);
    echo "var:ok\n";
} catch (Throwable $e) {
    echo "var:", get_class($e), "\n";
}
