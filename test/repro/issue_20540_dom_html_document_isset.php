<?php
/**
 * Dom\HTMLDocument isset($doc->body)/empty() must match live reads (#20540, re-#19580).
 * php-src: ext/dom/html_document.c
 */
$html = '<html><head><title>T</title></head><body><p>x</p></body></html>';
$d = Dom\HTMLDocument::createFromString($html);
echo 'body=', get_class($d->body), ' tag=', $d->body->tagName, "\n";
echo 'isset_body=', var_export(isset($d->body), true), "\n";
echo 'empty_body=', var_export(empty($d->body), true), "\n";
echo 'title=', var_export($d->title, true), ' isset_title=', var_export(isset($d->title), true), "\n";
echo 'empty_title=', var_export(empty($d->title), true), "\n";
$blank = Dom\HTMLDocument::createFromString('<html><head><title></title></head><body></body></html>');
echo 'blank_isset=', var_export(isset($blank->title), true), ' blank_empty=', var_export(empty($blank->title), true), "\n";
