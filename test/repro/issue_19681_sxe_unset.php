<?php
/**
 * Issue #19681 — SimpleXMLElement property/dim unset removes child nodes.
 */
$s = simplexml_load_string('<r><a>1</a><b>2</b></r>');
unset($s->a);
echo $s->asXML();
$s2 = simplexml_load_string('<r><a>1</a><a>2</a></r>');
unset($s2->a[0]);
echo $s2->asXML();
