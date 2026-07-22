<?php
/**
 * #22345 — @$doc->loadHTML(NOIMPLIED|NODEFDTD) then trim(saveHTML()) must be the
 * fragment, not loadHTML's bool ("1"). Compiler: MethodCall producer after END_SILENCE
 * must not steal the @ return slot (lib/Compiler.php; ext/dom/html_document.c).
 */
$d = new DOMDocument();
$ok = @$d->loadHTML('<p>x</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
echo 'ok=', var_export($ok, true), "\n";
echo 'html=', trim($d->saveHTML()), "\n";
