<?php
/**
 * Issue #23683 (re-#23665) — final plain properties vs Zend/php-src.
 *
 * All `final` property syntax lives inside eval() strings so the reference
 * profile can still parse this file (php-parser rejects `final` props on 8.2).
 *
 *   php bin/vm.php test/repro/maintainer_gap_final_plain_properties_re23665.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_final_plain_properties_re23665.php 8.4
 *
 * php-src-strict (verified Zend 8.2.32 / 8.4.23):
 * - default: declaring `public final` Fatals like Zend 8.2
 * - PROFILE=8.4: writes OK; isFinal=1 (instance + static); child override Fatals
 */
$want84 = ($argv[1] ?? '') === '8.4' || getenv('PHP_COMPILER_PROFILE') === '8.4';
echo 'profile=', getenv('PHP_COMPILER_PROFILE') ?: 'unset', "\n";

if (!$want84) {
    // Zend 8.2 / reference profile: E_COMPILE_ERROR (not catchable).
    eval('class T { public final string $x = "a"; }');
    echo "declare=ok\n";
    exit(1);
}

eval('class A { public final string $x = "a"; }');
$a = new A();
$a->x = 'z';
echo 'write=ok value=', $a->x, "\n";
echo 'isFinal=', (new ReflectionProperty('A', 'x'))->isFinal() ? '1' : '0', "\n";

eval('class S { public final static string $s = "s"; }');
S::$s = 't';
echo 'static_write=ok value=', S::$s, "\n";
echo 'static_isFinal=', (new ReflectionProperty('S', 's'))->isFinal() ? '1' : '0', "\n";

// Zend: E_COMPILE_ERROR — process exits after the lines above.
eval('class B extends A { public string $x = "b"; }');
echo "override=ok\n";
exit(1);
