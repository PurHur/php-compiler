<?php
// #36081: {main} script-global reads emit undefined-variable E_WARNING (Zend/zend_execute.c).
// Force E_ALL so the differential harness (-d error_reporting=1) still surfaces the warning.
error_reporting(E_ALL);
echo $missing;
