<?php
/**
 * AOT: ReflectionExtension::getINIEntries for filter/openssl/mbstring/session (#34193).
 * Zend counts: filter=2 openssl=2 mbstring=11 session=30; AOT pre-fix: all 0.
 */
$parts = [];
foreach (['filter', 'openssl', 'mbstring', 'session'] as $ext) {
    $e = new ReflectionExtension($ext);
    $c = $e->getINIEntries();
    $parts[] = $ext.'='.(is_array($c) ? count($c) : 'n/a');
}
echo implode(' ', $parts), "\n";
