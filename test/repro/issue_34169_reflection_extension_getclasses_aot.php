<?php
/**
 * AOT: ReflectionExtension::getClasses — thin proxy (#34169).
 * Zend/VM: count=6 DateTime=ReflectionClass; AOT pre-fix: NULL → count TypeError.
 */
$e = new ReflectionExtension('date');
$c = $e->getClasses();
echo 'type=', gettype($c);
echo ' count=', is_array($c) ? count($c) : 'n/a';
echo ' DateTime=', is_array($c) && isset($c['DateTime']) && $c['DateTime'] instanceof ReflectionClass ? '1' : '0';
echo "\n";
