<?php
// #35067 — string locals + runtime flags so VmString compile-time fold cannot hide
// a broken HtmlEntitiesJitHelper NestedJIT unit (leftover #35050 cheap green).
$cafe = 'caf' . "\xC3\xA9";
$eacute = "\xC3\xA9";
$ascii = '<' . 'a>&b';
$noop = 'no' . 'op';
$flags = 0;
$flags = $flags + ENT_QUOTES;
$html5 = 0;
$html5 = $html5 + ENT_HTML5;
echo htmlentities($cafe, $flags, 'UTF-8'), "\n";
echo htmlentities($eacute, $html5, 'UTF-8'), "\n";
echo htmlentities($ascii, $flags, 'UTF-8'), "\n";
echo htmlentities($noop, $flags, 'UTF-8'), "\n";
