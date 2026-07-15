<?php

$doc = new DOMDocument();
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$attr = $doc->createAttributeNS('http://example.com', 'p:a');
restore_error_handler();

if (false !== $attr) {
    fwrite(STDERR, 'fail: expected false, got '.get_debug_type($attr)."\n");
    exit(1);
}
if (1 !== count($warnings)) {
    fwrite(STDERR, 'fail: expected 1 warning, got '.count($warnings)."\n");
    exit(1);
}
if ('DOMDocument::createAttributeNS(): Document Missing Root Element' !== $warnings[0]) {
    fwrite(STDERR, 'fail: warning '.$warnings[0]."\n");
    exit(1);
}

echo "ok\n";
