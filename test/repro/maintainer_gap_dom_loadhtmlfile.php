<?php

declare(strict_types=1);

// Issue #18734 — DOMDocument::loadHTMLFile() file-path HTML load (ext/dom/php_dom.c).

$doc = new DOMDocument();
if (!method_exists($doc, 'loadHTMLFile')) {
    fwrite(STDERR, "fail: loadHTMLFile not registered\n");
    exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'domlf');
if (false === $tmp) {
    fwrite(STDERR, "fail: tempnam\n");
    exit(1);
}
file_put_contents($tmp, '<p id="target">hello</p>');

if (true !== $doc->loadHTMLFile($tmp)) {
    fwrite(STDERR, "fail: loadHTMLFile returned false\n");
    exit(1);
}
$found = $doc->getElementById('target');
if (null === $found || 'hello' !== $found->textContent) {
    $text = null === $found ? null : $found->textContent;
    fwrite(STDERR, 'fail: getElementById text '.var_export($text, true)."\n");
    exit(1);
}

try {
    $doc->loadHTMLFile('');
    fwrite(STDERR, "fail: empty filename should throw ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ('DOMDocument::loadHTMLFile(): Argument #1 ($filename) must not be empty' !== $e->getMessage()) {
        fwrite(STDERR, 'fail: empty filename message: '.$e->getMessage()."\n");
        exit(1);
    }
}

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$missing = $doc->loadHTMLFile('/nonexistent-dom-loadhtmlfile-18734.html');
restore_error_handler();
unlink($tmp);

if (false !== $missing) {
    fwrite(STDERR, "fail: missing file should return false\n");
    exit(1);
}
$hasIoWarning = false;
foreach ($warnings as $warning) {
    if (str_contains($warning, 'DOMDocument::loadHTMLFile(): I/O warning : failed to load external entity')) {
        $hasIoWarning = true;
    }
}
if (!$hasIoWarning) {
    fwrite(STDERR, "fail: missing I/O warning\n");
    exit(1);
}

echo "ok\n";
