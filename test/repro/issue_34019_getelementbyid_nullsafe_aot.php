<?php
// Repro #34019 — AOT inline getElementById(...)?->tagName after miss must not SIGSEGV.
$d = new DOMDocument();
$d->loadXML('<r/>');
echo ($d->getElementById('nope')?->tagName ?? 'null'), PHP_EOL;
$e = $d->getElementById('nope');
echo ($e?->tagName ?? 'null'), PHP_EOL;
