<?php
// AOT: ReflectionExtension::getINIEntries for filter/openssl/mbstring/session (#34193).
$parts = [];
foreach (['filter', 'openssl', 'mbstring', 'session'] as $ext) {
    $c = (new ReflectionExtension($ext))->getINIEntries();
    $parts[] = $ext.'='.(is_array($c) ? count($c) : 'n/a');
}
$f = (new ReflectionExtension('filter'))->getINIEntries();
$parts[] = 'filter.default='.(is_array($f) && array_key_exists('filter.default', $f) ? '1' : '0');
$parts[] = 'filter.default_flags_null='.(is_array($f) && array_key_exists('filter.default_flags', $f) && $f['filter.default_flags'] === null ? '1' : '0');
$o = (new ReflectionExtension('openssl'))->getINIEntries();
$parts[] = 'openssl.cafile_null='.(is_array($o) && array_key_exists('openssl.cafile', $o) && $o['openssl.cafile'] === null ? '1' : '0');
$m = (new ReflectionExtension('mbstring'))->getINIEntries();
$parts[] = 'mbstring.language='.(is_array($m) && array_key_exists('mbstring.language', $m) ? '1' : '0');
$s = (new ReflectionExtension('session'))->getINIEntries();
$parts[] = 'session.name='.(is_array($s) && array_key_exists('session.name', $s) ? '1' : '0');
echo implode(' ', $parts);
