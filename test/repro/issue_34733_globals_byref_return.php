<?php
/**
 * #34733 — AOT by-ref return of $GLOBALS['x'] must alias the live cell (re-#34717).
 *
 * @see php-src Zend/zend_execute.c ZEND_FETCH_DIM_W / ZEND_RETURN_BY_REF
 */
$GLOBALS['x'] = 1;

function &f_globals()
{
    return $GLOBALS['x'];
}

echo 'read:';
var_dump(f_globals());

$a = &f_globals();
$a = 5;
echo 'write:';
var_dump($GLOBALS['x']);
var_dump(f_globals());
