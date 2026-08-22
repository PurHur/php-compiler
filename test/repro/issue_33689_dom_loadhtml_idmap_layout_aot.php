<?php
/**
 * #33689 — AOT loadHTML must not SEGV (PROP_ELEMENT_ID_MAP in allocate layout).
 * php-src: ext/dom/document.c dom_document_load_html; php_dom.c get_element_by_id.
 */
$d = new DOMDocument();
$ok = @$d->loadHTML('<div id="x">t</div>');
echo $ok ? "ok\n" : "fail\n";
$e = $d->getElementById('x');
if ($e === null) {
    echo "null\n";
} else {
    echo $e->tagName, "\n";
}
