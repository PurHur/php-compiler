<?php
/**
 * Maintainer repro: private(set) must not compile on Zend 8.2 reference profile (#12508).
 *
 * Zend 8.2: Parse error · VM reference profile: CompileFatal (rejector).
 */
class C
{
    public function __construct(private(set) int $x) {}
}
