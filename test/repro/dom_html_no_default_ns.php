<?php

declare(strict_types=1);

/**
 * Repro #26008 — Dom\HTML_NO_DEFAULT_NS defined + createFromString omits default XHTML ns.
 *
 * Zend/php-src 8.4: defined; createFromString(..., Dom\HTML_NO_DEFAULT_NS) → namespaceURI NULL.
 * createElement after parse still uses http://www.w3.org/1999/xhtml.
 */
if (!class_exists('Dom\\HTMLDocument')) {
    fwrite(STDERR, "fail: Dom\\HTMLDocument missing (need PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}

if (!defined('Dom\\HTML_NO_DEFAULT_NS')) {
    fwrite(STDERR, "fail: Dom\\HTML_NO_DEFAULT_NS undefined\n");
    exit(1);
}

if (Dom\HTML_NO_DEFAULT_NS !== (1 << 20)) {
    fwrite(STDERR, 'fail: Dom\\HTML_NO_DEFAULT_NS value '.Dom\HTML_NO_DEFAULT_NS."\n");
    exit(1);
}

$d = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div id="d">x</div></body></html>',
    Dom\HTML_NO_DEFAULT_NS
);
$el = $d->getElementById('d');
$ns = $el->namespaceURI;
if (null !== $ns) {
    fwrite(STDERR, "fail: expected NULL namespaceURI, got ".var_export($ns, true)."\n");
    exit(1);
}
if (!($el instanceof Dom\HTMLElement)) {
    fwrite(STDERR, 'fail: expected Dom\\HTMLElement, got '.get_class($el)."\n");
    exit(1);
}

$created = $d->createElement('span');
if ('http://www.w3.org/1999/xhtml' !== $created->namespaceURI) {
    fwrite(STDERR, 'fail: createElement ns='.var_export($created->namespaceURI, true)."\n");
    exit(1);
}

$path = sys_get_temp_dir().'/phpc_html_no_default_ns_'.getmypid().'.html';
file_put_contents($path, '<!DOCTYPE html><html><body><p id="p">y</p></body></html>');
$fromFile = Dom\HTMLDocument::createFromFile($path, Dom\HTML_NO_DEFAULT_NS);
@unlink($path);
$fileNs = $fromFile->getElementById('p')->namespaceURI;
if (null !== $fileNs) {
    fwrite(STDERR, 'fail: createFromFile ns='.var_export($fileNs, true)."\n");
    exit(1);
}

echo "ok\n";
