<?php
/**
 * #25536 — convert_uudecode Reflection return string|false
 * (ext/standard/string.stub.php / uuencode.c).
 */
$r = new ReflectionFunction('convert_uudecode');
echo 'convert_uudecode=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$enc = convert_uuencode('cat');
$dec = convert_uudecode($enc);
echo 'roundtrip=', ($dec === 'cat') ? '1' : '0', "\n";
