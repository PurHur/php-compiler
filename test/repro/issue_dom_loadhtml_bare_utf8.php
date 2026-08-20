<?php
/**
 * #22023 — loadHTML bare UTF-8 + getElementsByTagName AOT (hex only; encoding read is #32668).
 */
$d = new DOMDocument();
@$d->loadHTML('<p>café</p>');
$bare = $d->getElementsByTagName('p')->item(0)->textContent;
echo 'bare_hex=', bin2hex($bare), "\n";

$d2 = new DOMDocument();
@$d2->loadHTML('<meta charset="utf-8"><p>café</p>');
$meta = $d2->getElementsByTagName('p')->item(0)->textContent;
echo 'meta_hex=', bin2hex($meta), "\n";
