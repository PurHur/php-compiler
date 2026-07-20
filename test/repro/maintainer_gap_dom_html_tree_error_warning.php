<?php
declare(strict_types=1);
/**
 * #21523 — Dom\HTMLDocument::createFromString() must emit lexbor UNTOININMO E_WARNING
 * (php-src ext/dom/html_document.c) for fragments that start without a DOCTYPE.
 *
 * Zend 8.4.23: createFromString('<div>hi</div>') →
 *   E_WARNING Dom\HTMLDocument::createFromString(): tree error unexpected-token-in-initial-mode
 *   in Entity, line: 1, column: 2-4
 * plus body text "hi". LIBXML_NOERROR silences.
 */
if (!class_exists('Dom\\HTMLDocument')) {
    fwrite(STDERR, "skip: Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4\n");
    exit(0);
}

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $severity.':'.$message;
    return true;
});

$d = Dom\HTMLDocument::createFromString('<div>hi</div>');
$bodyEl = $d->body;
$body = null === $bodyEl ? '' : $bodyEl->textContent;

$silent = [];
set_error_handler(static function (int $severity, string $message) use (&$silent): bool {
    $silent[] = $severity.':'.$message;
    return true;
});
$d2 = Dom\HTMLDocument::createFromString('<div>hi</div>', LIBXML_NOERROR);
$body2El = $d2->body;
$body2 = null === $body2El ? '' : $body2El->textContent;
restore_error_handler();
restore_error_handler();

$okDoc = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div>ok</div></body></html>'
);
$okEl = $okDoc->body;
$bodyOk = null === $okEl ? '' : $okEl->textContent;

echo 'body=', $body, "\n";
echo 'warn_count=', count($warnings), "\n";
echo 'warn0=', $warnings[0] ?? '', "\n";
echo 'noerror_body=', $body2, ' noerror_warns=', count($silent), "\n";
echo 'doctype_body=', $bodyOk, "\n";

$expect = '2:Dom\\HTMLDocument::createFromString(): tree error unexpected-token-in-initial-mode in Entity, line: 1, column: 2-4';
$ok = $body === 'hi'
    && 1 === count($warnings)
    && ($warnings[0] ?? '') === $expect
    && $body2 === 'hi'
    && 0 === count($silent)
    && $bodyOk === 'ok';
echo $ok ? "dom_html_tree_error_warning ok\n" : "dom_html_tree_error_warning fail\n";
exit($ok ? 0 : 1);
