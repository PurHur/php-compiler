<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

final class IncludeRequireVMTest extends TestCase
{
    public function testIncludeAndOnceReturnValuesAndCallerScope(): void
    {
        $dir = sys_get_temp_dir().'/phpc-include-require-'.getmypid().'-'.bin2hex(random_bytes(4));
        self::assertTrue(@mkdir($dir, 0777, true) || is_dir($dir));

        $main = $dir.'/main.php';
        $lib = $dir.'/lib.php';
        $lib2 = $dir.'/lib2.php';
        $noreturn = $dir.'/noreturn.php';
        $defs = $dir.'/defs.php';

        file_put_contents($lib, "<?php\n\$x = (\$x ?? 0) + 1;\nreturn \$x;\n");
        file_put_contents($lib2, "<?php\n\$y = (\$y ?? 0) + 1;\nreturn \$y;\n");
        file_put_contents($noreturn, "<?php\n\$side = (\$side ?? 0) + 1;\n");
        file_put_contents($defs, "<?php\nfunction inc_fn() { return 1; }\nclass IncC {}\n");
        file_put_contents(
            $main,
            <<<'PHP'
<?php
$__probe = 1;
echo "start\n";
$lib = __DIR__ . '/lib.php';
$lib2 = __DIR__ . '/lib2.php';
$noreturn = __DIR__ . '/noreturn.php';
$defs = __DIR__ . '/defs.php';

$x = 0;
$a = include $lib;
$b = include $lib;
if ($x === 2 && $a === 1 && $b === 2) {
    echo "ok-include\n";
} else {
    echo "bad-include\n";
}

$x = 0;
$a = include_once $lib;
$b = include_once $lib;
if ($x === 1 && $a === 1 && $b === true) {
    echo "ok-include-once\n";
} else {
    echo "bad-include-once\n";
}

$side = 0;
$a = include $noreturn;
$b = include $noreturn;
if ($side === 2 && $a === 1 && $b === 1) {
    echo "ok-noreturn\n";
} else {
    echo "bad-noreturn\n";
}

// resolved-path caching for include_once (same file via different paths)
$y = 0;
include_once $lib2;
include_once (__DIR__ . '/./lib2.php');
include_once realpath($lib2);
if ($y === 1) {
    echo "ok-resolve-once\n";
} else {
    echo "bad-resolve-once\n";
}

include $defs;
if (function_exists('inc_fn')) {
    echo "ok-fn\n";
} else {
    echo "bad-fn\n";
}
if (class_exists('IncC')) {
    echo "ok-class\n";
} else {
    echo "bad-class\n";
}

$missing = __DIR__ . '/missing.php';
if ((@include $missing) === false) {
    echo "ok-missing-include\n";
} else {
    echo "bad-missing-include\n";
}
$missingAbs = '/tmp/no_such_'.getmypid().'.php';
if ('false' === var_export(@include $missingAbs, true)) {
    echo "ok-suppress-include-expr\n";
} else {
    echo "bad-suppress-include-expr\n";
}
if ('false' === var_export(@include_once $missingAbs, true)) {
    echo "ok-suppress-include-once-expr\n";
} else {
    echo "bad-suppress-include-once-expr\n";
}
try {
    require $missing;
    echo "unreachable\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
PHP
        );

        $runtime = new Runtime();
        $code = (string) file_get_contents($main);
        $block = $runtime->parseAndCompile($code, $main);
        self::assertNotNull($block);

        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();

        self::assertSame(
            "start\n".
            "ok-include\n".
            "ok-include-once\n".
            "ok-noreturn\n".
            "ok-resolve-once\n".
            "ok-fn\n".
            "ok-class\n".
            "ok-missing-include\n".
            "ok-suppress-include-expr\n".
            "ok-suppress-include-once-expr\n".
            "Error\n",
            $out
        );
    }
}

