<?php
/**
 * AOT: ReflectionExtension::getClasses — thin proxy (#34169).
 * Zend/VM: count=6 DateTime=ReflectionClass; AOT pre-fix: NULL → count TypeError.
 */
$e = new ReflectionExtension('date');
$c = $e->getClasses();
echo 'type=', gettype($c);
echo ' count=', is_array($c) ? count($c) : 'n/a';
$dt = is_array($c) && isset($c['DateTime']) ? $c['DateTime'] : null;
echo ' DateTime=', ($dt instanceof ReflectionClass) ? '1' : '0';
echo "\n";
