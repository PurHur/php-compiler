--TEST--
stdlib DOMDocument documentURI after loadXML (#14468, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
echo var_export($d->documentURI, true), "\n";
$d->loadXML('<a/>');
$uri = $d->documentURI;
$cwd = getcwd();
$expected = (false !== $cwd && '' !== $cwd)
    ? (str_ends_with($cwd, '/') ? $cwd : $cwd.'/')
    : '/';
echo ($uri === $expected) ? "uri_ok\n" : "uri_fail\n";
?>
--EXPECT--
NULL
uri_ok
