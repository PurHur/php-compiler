--TEST--
Dom\HTMLDocument::createFromString() UNTOININMO tree-error E_WARNING (#21523)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#21523)');
}
?>
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $severity.':'.$message;
    return true;
});
$d = Dom\HTMLDocument::createFromString('<div>hi</div>');
$bodyEl = $d->body;
echo 'body=', null === $bodyEl ? '' : $bodyEl->textContent, "\n";
echo 'warn=', $warnings[0] ?? '', "\n";

$silent = [];
set_error_handler(static function (int $severity, string $message) use (&$silent): bool {
    $silent[] = $message;
    return true;
});
$d2 = Dom\HTMLDocument::createFromString('<div>hi</div>', LIBXML_NOERROR);
$body2El = $d2->body;
echo 'noerror_body=', null === $body2El ? '' : $body2El->textContent, ' silent=', count($silent), "\n";
restore_error_handler();
restore_error_handler();

$ok = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>x</body></html>');
$okEl = $ok->body;
echo 'doctype_body=', null === $okEl ? '' : $okEl->textContent, ' doctype_extra=', count($warnings) > 1 ? 'yes' : 'no', "\n";

libxml_use_internal_errors(true);
Dom\HTMLDocument::createFromString('<p>x</p>');
$errs = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors(false);
echo 'internal=', isset($errs[0]) ? trim($errs[0]->message) : '', "\n";
?>
--EXPECT--
body=hi
warn=2:Dom\HTMLDocument::createFromString(): tree error unexpected-token-in-initial-mode in Entity, line: 1, column: 2-4
noerror_body=hi silent=0
doctype_body=x doctype_extra=no
internal=tree error unexpected-token-in-initial-mode in Entity, line: 1, column: 2
