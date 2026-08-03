<?php
/**
 * Issue #27123 — promoted `final` is PHP 8.5+; PROFILE=8.4 must reject like Zend.
 *
 * php-src: Zend/zend_language_parser.y / zend_compile.c —
 * "Cannot use the final modifier on a parameter"
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer-gaps/final_promoted_84.php
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/maintainer-gaps/final_promoted_84.php
 */
class C
{
    public function __construct(public final string $name)
    {
    }
}
echo (new C('x'))->name, "\n";
