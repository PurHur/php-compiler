--TEST--
Language: duplicate file-scope const redefinition — E_WARNING, first value kept (#6938, zend_constants.c)
--RUNFILE--
../../../repro/const_redefine_warning.php
--EXPECTF--
PHP Warning:  Constant X already defined in %s on line %d
1
