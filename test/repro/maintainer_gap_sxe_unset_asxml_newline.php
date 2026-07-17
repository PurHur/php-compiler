<?php
/**
 * Issue #19934 — consecutive asXML() after unset must match Zend trailing newline.
 */
$s = simplexml_load_string('<r><a>1</a><b>2</b></r>');
unset($s->a);
echo $s->asXML();
$s2 = simplexml_load_string('<r><a>1</a></r>');
echo $s2->asXML();
