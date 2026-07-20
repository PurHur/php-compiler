<?php
/**
 * #21559 — Dom\ ParentNode::$children is undefined on PROFILE=8.4 (Zend 8.4.23).
 * childElementCount / firstElementChild / lastElementChild remain available.
 *
 * Uses @ for the undefined-property read so AOT (no set_error_handler shim) can
 * assert type/isset without depending on error-handler JIT (#1379).
 */
$html = Dom\HTMLDocument::createFromString(
    '<html><body><div id="d"><p>a</p></div></body></html>',
    LIBXML_NOERROR
);
$d = $html->getElementById('d');
$c = @$d->children;
echo 'type=', get_debug_type($c), ' isset=', var_export(isset($d->children), true), PHP_EOL;
echo 'count=', $d->childElementCount, PHP_EOL;
