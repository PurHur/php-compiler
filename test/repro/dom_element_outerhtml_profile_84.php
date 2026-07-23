<?php
/**
 * #22482 — Dom\Element::$outerHTML is undefined on PROFILE=8.4 (Zend 8.4 stubs).
 * $innerHTML remains a live string property.
 *
 * Uses @ for the undefined-property read so AOT (no set_error_handler shim) can
 * assert type/isset without depending on error-handler JIT (#1379).
 */
$html = Dom\HTMLDocument::createFromString(
    '<!doctype html><html><body><div id="d"><p>Hi</p></div></body></html>',
    LIBXML_NOERROR
);
$el = $html->getElementById('d');
echo 'isset_inner=', var_export(isset($el->innerHTML), true), PHP_EOL;
echo 'isset_outer=', var_export(isset($el->outerHTML), true), PHP_EOL;
$outer = @$el->outerHTML;
echo 'outer_type=', get_debug_type($outer), PHP_EOL;
