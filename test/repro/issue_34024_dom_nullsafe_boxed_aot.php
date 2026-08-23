<?php
// Repro #34024 — AOT cloneNode(...)?->tagName must match Zend (not empty).
// Note: `(call()?->tagName ?? 'null')` on a *hit* still prints empty on master for several
// DOM call results (createElement/importNode too) — coalesce+nullsafe follow-up, not this fix.
$d = new DOMDocument();
$d->loadXML('<r/>');
echo $d->documentElement->cloneNode(false)?->tagName, PHP_EOL;
$n = $d->documentElement->cloneNode(false);
echo $n?->tagName, PHP_EOL;
echo $n->tagName, PHP_EOL;
echo ($d->getElementById('nope')?->tagName ?? 'null'), PHP_EOL;
