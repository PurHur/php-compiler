<?php
/**
 * #27300 — Dom\HTMLDocument::createFromString + body->textContent under AOT.
 * Requires PHP_COMPILER_PROFILE=8.4 (living Dom\ namespace).
 */
$d = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body><p>hi</p></body></html>');
echo $d->body->textContent, "\n";
