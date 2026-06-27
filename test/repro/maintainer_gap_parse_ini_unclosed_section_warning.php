<?php

declare(strict_types=1);

@parse_ini_string("a=1\n[sec");
$msg = error_get_last()['message'] ?? '';
if (!str_contains($msg, "expecting ']'")) {
    echo "fail: warning text: {$msg}\n";
    exit(1);
}

$result = @parse_ini_string("a=1\n[sec");
if (false !== $result) {
    echo "fail: expected false\n";
    exit(1);
}

echo "ok\n";
