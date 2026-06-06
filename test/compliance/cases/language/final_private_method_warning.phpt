--TEST--
Language: final private method emits compile-time E_WARNING (#6914, zend_compile.c)
--RUNFILE--
../../../repro/maintainer_final_private.php
--EXPECTF--
PHP Warning:  Private methods cannot be final as they are never overridden by other classes in %s on line %d
compiled
