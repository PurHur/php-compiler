<?php
/**
 * AOT: ReflectionExtension::getConstants — thin proxy (#34162).
 * Zend/VM: count=17 DATE_ATOM=1; AOT pre-fix: NULL → count TypeError.
 */
$e = new ReflectionExtension('date');
$c = $e->getConstants();
echo 'type=', gettype($c);
echo ' count=', is_array($c) ? count($c) : 'n/a';
echo ' DATE_ATOM=', is_array($c) && isset($c['DATE_ATOM']) ? '1' : '0';
echo ' SUNFUNCS_RET_DOUBLE=', is_array($c) && isset($c['SUNFUNCS_RET_DOUBLE']) && 2 === $c['SUNFUNCS_RET_DOUBLE'] ? '1' : '0';
echo "\n";
