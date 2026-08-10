<?php
/**
 * #29853 — AOT DOMDocument::adoptNode() must not abort; return is usable.
 *
 * VM PROFILE=8.4: adopt + nodeName.
 * Default VM: Error "Not yet implemented".
 * Full saveXML after dual loadXML+appendChild is a separate thin-AOT INNER_XML
 * / multi-document issue (repro before adopt already drifts on master).
 */
$d1 = new DOMDocument();
$d1->loadXML('<a><b/></a>');
$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$n = $d2->adoptNode($d1->documentElement->firstChild);
echo get_class($n), ':', $n->nodeName, "\n";
