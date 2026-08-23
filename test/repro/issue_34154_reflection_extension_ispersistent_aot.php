<?php
/**
 * AOT: ReflectionExtension::isPersistent / isTemporary — thin proxies (#34154).
 * Zend/VM: persistent=1 temporary=0; AOT pre-fix: NULL → type=NULL.
 */
$e = new ReflectionExtension('date');
echo 'persistent=', $e->isPersistent() ? '1' : '0';
echo ' temporary=', $e->isTemporary() ? '1' : '0';
echo "\n";
