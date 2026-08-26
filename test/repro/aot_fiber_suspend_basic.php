<?php
/**
 * #35188 — AOT Fiber::suspend in callback must compile and match Zend.
 * precompileClosuresBeforeQueue must not lower Fiber suspend closures
 * (needs compileResumeFunction / compilingFiberResume).
 * php-src: Zend/zend_fibers.c zend_fiber_suspend
 */
$fiber = new Fiber(function (): void {
    Fiber::suspend('ok');
});
echo $fiber->start(), "\n";
