<?php
/** Repro #22406 — SimpleXMLIterator factory/construct (ext/simplexml/sxe.c). */
$x = simplexml_load_string('<r><a>1</a></r>', 'SimpleXMLIterator');
echo 'load_class=', get_class($x), "\n";
echo 'load_instanceof=', ($x instanceof SimpleXMLIterator) ? '1' : '0', "\n";
$y = new SimpleXMLIterator('<r><a/></r>');
echo 'new_class=', get_class($y), "\n";
$y->rewind();
echo 'child_class=', $y->valid() ? get_class($y->current()) : 'none', "\n";
