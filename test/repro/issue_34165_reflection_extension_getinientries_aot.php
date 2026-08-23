<?php
/**
 * AOT: ReflectionExtension::getINIEntries — thin proxy (#34165).
 * Zend/VM: count=5 date.timezone set; AOT pre-fix: NULL → count TypeError.
 */
$e = new ReflectionExtension('date');
$c = $e->getINIEntries();
echo 'type=', gettype($c);
echo ' count=', is_array($c) ? count($c) : 'n/a';
echo ' date.timezone=', is_array($c) && array_key_exists('date.timezone', $c) ? '1' : '0';
echo ' date.default_latitude=', is_array($c) && array_key_exists('date.default_latitude', $c) ? '1' : '0';
echo "\n";
