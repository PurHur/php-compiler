<?php
/**
 * AOT DOMXPath relative `.` / `.//tag` + JitStringBuiltinArg import (#31738 / re-#20257).
 */
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:a="urn:a"><a:x id="1">A</a:x><y id="2">B</y><z><!--c-->C</z></root>');
$el = $doc->documentElement;
$xp = new DOMXPath($doc);
$rel = $xp->query('.//y', $el);
echo 'rel=', $rel->length, ':', $rel->item(0)->nodeName, "\n";
$self = $xp->query('.', $el);
echo 'self=', $self->length, ':', $self->item(0)->nodeName, "\n";
$abs = $xp->query('//y');
echo 'abs=', $abs->length, ':', $abs->item(0)->nodeName, "\n";
echo "ok\n";
