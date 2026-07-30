<?php
/**
 * Issue #25501 — PROFILE=8.4 final plain property eval override (php-src-strict).
 *
 * Cross-eval child override must emit Zend-shaped Fatal on bin/vm.php and
 * bin/jit.php (VM-fallback must not silent-exit 255 with empty stderr).
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_25501_final_plain_property_profile_matrix.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_25501_final_plain_property_profile_matrix.php
 *   # expect exit 255 + Fatal error: Cannot override final property P::$x … eval()'d code
 *   # never: override=ok / silent exit
 *
 * Reference-profile reject (no PROFILE): see maintainer_gap_final_plain_property_refprofile*.php
 * php-src: Zend/zend_inheritance.c — "Cannot override final property %s::$%s"
 */
eval('class P { final public string $x = "z"; }');
$p = new P;
if ($p->x !== 'z') {
    echo "value-bad\n";
}
$p->x = 'w';
if ($p->x !== 'w') {
    echo "write-failed\n";
}
if (!(new ReflectionProperty('P', 'x'))->isFinal()) {
    echo "not-final\n";
}
eval('class C extends P { public string $x = "c"; }');
echo "override=ok\n";
