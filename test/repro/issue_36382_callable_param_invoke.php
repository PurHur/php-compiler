<?php
/**
 * #36382 — AOT `$cb()` on a callable parameter must invoke the Closure
 * (FastRoute simpleDispatcher). php-src: ZEND_INIT_DYNAMIC_CALL /
 * Zend/zend_closures.c (zend_create_closure / __invoke).
 */
function callit(callable $cb): void
{
    $cb();
}
callit(function () {
    echo "CB_OK\n";
});
echo "OK\n";
