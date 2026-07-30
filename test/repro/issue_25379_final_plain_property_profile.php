<?php

declare(strict_types=1);

/**
 * Issue #25379 (re-#23403 / #24895) — final plain properties vs PROFILE.
 *
 * php-src-strict (Zend/zend_compile.c + zend_inheritance.c + ext/reflection):
 * - default / PROFILE=8.2: compile Fatal — final only for methods/classes/constants
 * - PROFILE=8.4: isFinal() true; writes allowed (inheritance-only); child override Fatals
 *
 * Override lives in eval() so the 8.4 write/isFinal lines run before the Fatal
 * (same shape as maintainer_gap_final_plain_properties_re23665.php).
 *
 * Run:
 *   php bin/vm.php test/repro/issue_25379_final_plain_property_profile.php
 *   PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/issue_25379_final_plain_property_profile.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_25379_final_plain_property_profile.php 8.4
 */

$want84 = ($argv[1] ?? '') === '8.4' || getenv('PHP_COMPILER_PROFILE') === '8.4';
echo 'profile=', getenv('PHP_COMPILER_PROFILE') ?: 'unset', "\n";

if (!$want84) {
    // Zend 8.2 / reference: E_COMPILE_ERROR (not catchable). Syntax inside eval so the
    // outer file still parses when a host/tooling gate rejects `final` on properties.
    eval('class P { final public string $x = "z"; }');
    echo "declare=ok\n";
    exit(1);
}

eval('class P { final public string $x = "z"; }');
$p = new P();
echo 'value=', $p->x, "\n";
$p->x = 'w';
echo 'wrote=', $p->x, "\n";
echo 'isFinal=', (new ReflectionProperty('P', 'x'))->isFinal() ? '1' : '0', "\n";

// Zend: E_COMPILE_ERROR — process exits after the lines above.
eval('class C extends P { public string $x = "c"; }');
echo "override=ok\n";
exit(1);
