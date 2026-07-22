<?php
/**
 * #22022 — loadHTML('<?xml encoding="utf-8">…') must keep body elements (libxml htmlReadMemory).
 */
$d = new DOMDocument();
@$d->loadHTML('<?xml encoding="utf-8"><p id="x">café</p>');
echo 'length=', $d->getElementsByTagName('p')->length, "\n";
$p = $d->getElementsByTagName('p')->item(0);
echo 'text=', $p ? $p->textContent : '(null)', "\n";
echo 'id=', $p ? $p->getAttribute('id') : '(null)', "\n";
