<?php
/**
 * AOT: ReflectionExtension::getFunctions — thin proxy (#34177).
 * Zend/VM: count=48 strtotime=ReflectionFunction; AOT pre-fix: NULL → count TypeError.
 */
$e = new ReflectionExtension('date');
$fns = $e->getFunctions();
echo 'type=', gettype($fns);
echo ' count=', is_array($fns) ? count($fns) : 'n/a';
echo ' strtotime=', is_array($fns) && isset($fns['strtotime']) && $fns['strtotime'] instanceof ReflectionFunction
    && 'strtotime' === $fns['strtotime']->getName() ? '1' : '0';
echo "\n";
