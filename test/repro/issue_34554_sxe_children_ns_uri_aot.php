<?php
/**
 * #34554 — children($uri) defaults isPrefix=false (URI), not true (prefix).
 * php-src ext/simplexml/simplexml.stub.php / sxe.c
 *
 * Separate trees per assertion: AOT host-tree lookup falls back to lastTree after
 * children() materialize, so reuse of one $s across calls is not reliable yet.
 */
$s = new SimpleXMLElement('<r/>');
$s->addChild('x', '1', 'urn:x');
echo 'uri=', count($s->children('urn:x')), "\n";

$s2 = new SimpleXMLElement('<r/>');
$s2->addChild('x', '1', 'urn:x');
echo 'uri_explicit_false=', count($s2->children('urn:x', false)), "\n";

$s3 = new SimpleXMLElement('<r/>');
$s3->addChild('x', '1', 'urn:x');
echo 'uri_as_prefix=', count($s3->children('urn:x', true)), "\n";
