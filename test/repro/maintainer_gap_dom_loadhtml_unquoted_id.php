<?php

declare(strict_types=1);

// Issue #18319 — loadHTML() unquoted id attributes must index getElementById() (ext/dom/document.c).

$doc = new DOMDocument();
$doc->loadHTML('<p id=x>hi</p>');
$found = $doc->getElementById('x');
if (null === $found) {
    fwrite(STDERR, "fail: getElementById('x') returned null for unquoted id\n");
    exit(1);
}
if ('hi' !== $found->textContent) {
    fwrite(STDERR, 'fail: textContent expected "hi", got '.var_export($found->textContent, true)."\n");
    exit(1);
}

$doc2 = new DOMDocument();
$doc2->loadHTML("<p id='y'>there</p>");
$found2 = $doc2->getElementById('y');
if (null === $found2 || 'there' !== $found2->textContent) {
    fwrite(STDERR, "fail: single-quoted id lookup\n");
    exit(1);
}

echo "ok\n";
