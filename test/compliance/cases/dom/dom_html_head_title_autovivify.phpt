--TEST--
Dom\HTMLDocument body-only markup implies <head>; $title write persists (#26023)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$h = Dom\HTMLDocument::createFromString('<!doctype html><html><body></body></html>');
echo $h->head === null ? 'NULL' : $h->head->tagName, "\n";
$h->title = 'Hi';
echo json_encode($h->title), "\n";
$saved = $h->saveHtml();
echo (str_contains($saved, '<head><title>Hi</title></head>') ? 'saved_ok' : 'saved_fail'), "\n";

// Explicit head+title path must remain green.
$with = Dom\HTMLDocument::createFromString(
    '<!doctype html><html><head><title>Old</title></head><body></body></html>'
);
echo 'explicit=', $with->title, "\n";
$with->title = 'New';
echo 'explicit_set=', $with->title, "\n";

// LIBXML_HTML_NOIMPLIED: Zend leaves head absent (title write is a no-op).
$no = Dom\HTMLDocument::createFromString('<html><body></body></html>', LIBXML_HTML_NOIMPLIED);
echo $no->head === null ? 'noimplied_NULL' : 'noimplied_HEAD', "\n";
$no->title = 'X';
echo json_encode($no->title), "\n";
?>
--EXPECT--
HEAD
"Hi"
saved_ok
explicit=Old
explicit_set=New
noimplied_NULL
""
