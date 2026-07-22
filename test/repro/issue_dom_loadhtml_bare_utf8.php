<?php
/**
 * #22023 — loadHTML bare UTF-8 matches Zend/libxml Latin-1 default (mojibake).
 * With meta charset=utf-8, text stays UTF-8.
 */
$d = new DOMDocument();
@$d->loadHTML('<p>café</p>');
$bare = $d->getElementsByTagName('p')->item(0)->textContent;
echo 'bare=', $bare, "\n";
echo 'bare_hex=', bin2hex($bare), "\n";

$d2 = new DOMDocument();
@$d2->loadHTML('<meta charset="utf-8"><p>café</p>');
$meta = $d2->getElementsByTagName('p')->item(0)->textContent;
echo 'meta=', $meta, "\n";
echo 'meta_hex=', bin2hex($meta), "\n";
echo 'enc=', var_export($d2->encoding, true), "\n";
