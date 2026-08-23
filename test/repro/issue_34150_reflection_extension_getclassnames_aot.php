<?php
/**
 * AOT: ReflectionExtension::getClassNames — thin proxy (#34150).
 * Zend/VM: count=N DateTime=1; AOT pre-fix: NULL → count TypeError.
 */
$e = new ReflectionExtension('date');
$names = $e->getClassNames();
echo 'type=', gettype($names);
echo ' count=', is_array($names) ? count($names) : 'n/a';
echo ' DateTime=', is_array($names) && in_array('DateTime', $names, true) ? '1' : '0';
echo "\n";
