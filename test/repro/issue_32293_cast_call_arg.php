<?php
/**
 * #32293 — AOT (int)/(float) inline call-arg must match Zend ZEND_CAST + ZEND_SEND_VAL.
 * Dead-temp ARG_SEND used to materialize NULL (#31968 group 2 / peer #28622).
 */
function f($x)
{
    var_dump($x);
}
f((int) 1.9);
f((float) 2);
$n = (int) 1.9;
f($n);
$d = (float) 2;
f($d);
