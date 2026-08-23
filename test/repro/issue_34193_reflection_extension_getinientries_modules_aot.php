<?php
/**
 * AOT: ReflectionExtension::getINIEntries for filter/openssl/mbstring/session (#34193).
 * Zend/VM: non-zero counts with known keys; AOT pre-fix: count=0 for each.
 */
$f = (new ReflectionExtension('filter'))->getINIEntries();
$o = (new ReflectionExtension('openssl'))->getINIEntries();
$m = (new ReflectionExtension('mbstring'))->getINIEntries();
$s = (new ReflectionExtension('session'))->getINIEntries();

echo 'filter count=', count($f);
$fk = array_keys($f);
sort($fk);
echo ' keys=', implode(',', $fk);
echo "\n";

echo 'openssl count=', count($o);
$ok = array_keys($o);
sort($ok);
echo ' keys=', implode(',', $ok);
echo "\n";

echo 'mbstring count=', count($m);
echo ' internal_encoding=', array_key_exists('mbstring.internal_encoding', $m) ? '1' : '0';
echo "\n";

echo 'session count=', count($s);
echo ' name=', array_key_exists('session.name', $s) ? '1' : '0';
echo "\n";
