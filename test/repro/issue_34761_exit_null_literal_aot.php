<?php
// AOT: exit(null) literal must not compile-SIGSEGV (#34761).
// Zend/VM: empty stdout, rc=0 (PHP 8.2 profile).
exit(null);
