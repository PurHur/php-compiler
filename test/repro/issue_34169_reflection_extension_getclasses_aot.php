<?php
/**
 * AOT: ReflectionExtension::getClasses — thin proxy (#34169).
 * Zend/VM: count=6 DateTime=ReflectionClass; AOT pre-fix: NULL → count TypeError.
 */
$e = new ReflectionExtension('date');
$classes = $e->getClasses();
echo 'type=', gettype($classes);
echo ' count=', is_array($classes) ? count($classes) : 'n/a';
echo ' DateTime=', is_array($classes) && isset($classes['DateTime']) && $classes['DateTime'] instanceof ReflectionClass
    && 'DateTime' === $classes['DateTime']->getName() ? '1' : '0';
echo "\n";
