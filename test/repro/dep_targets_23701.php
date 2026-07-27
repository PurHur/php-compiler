<?php

declare(strict_types=1);

/**
 * Repro #23701 — #[\Deprecated] illegal targets under PROFILE=8.4.
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/dep_targets_23701.php
 *
 * Expect CompileError messages matching Zend allowed-targets list for
 * class / property / parameter / trait; function + class constant remain OK.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use PHPCompiler\Runtime;

$rt = new Runtime();
$cases = [
    'class' => '<?php #[\Deprecated("old")] class Old {}',
    'prop' => '<?php class P { #[\Deprecated("p")] public $x = 1; }',
    'param' => '<?php function g(#[\Deprecated("arg")] $a) { return $a; }',
    'trait' => '<?php #[\Deprecated("t")] trait Tr {}',
    'func' => '<?php #[\Deprecated("f")] function f_ok() {}',
    'const' => '<?php class C { #[\Deprecated("c")] public const X = 1; }',
];

foreach ($cases as $label => $code) {
    try {
        $rt->parseAndCompile($code, "dep_targets_{$label}.php");
        echo $label, "=ok\n";
    } catch (Throwable $e) {
        echo $label, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
