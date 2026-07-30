<?php
/**
 * #25293 — ReflectionFunction::invoke()/invokeArgs() must not warn on internal functions.
 */
$r = new ReflectionFunction('strlen');
echo $r->invoke('abc'), "\n";
echo $r->invokeArgs(['abc']), "\n";
echo "ok\n";
