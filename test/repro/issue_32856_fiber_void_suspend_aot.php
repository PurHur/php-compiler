<?php
/**
 * #32856 — AOT Fiber void callback with discarded suspend must match Zend.
 * Zend/VM: prints suspend value from start(); must not Module.php:180 or SIGSEGV.
 */
$fiber = new Fiber(function (): void {
    Fiber::suspend('ok');
});
echo $fiber->start(), "\n";
