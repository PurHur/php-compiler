<?php

declare(strict_types=1);

/**
 * Minimal NestedJIT repro: StreamLibcHandleJitHelper self::$ props (#22034 pillar-1).
 * Expect: compiles without "Cannot use self in the global scope".
 */

require __DIR__.'/../../vendor/autoload.php';

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\Runtime;

putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
$runtime = new Runtime(Runtime::MODE_AOT);
$ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
JitVmHelperLink::ensureCompiled(
    $ctx,
    '/ext/standard/StreamLibcHandleJitHelper.php',
    [
        'PHPCompiler\\ext\\standard\\StreamLibcHandleJitHelper::registerFromPtr',
        'PHPCompiler\\ext\\standard\\StreamLibcHandleJitHelper::markPopen',
        'PHPCompiler\\ext\\standard\\StreamLibcHandleJitHelper::resolvePtr',
    ],
    '#repro-self-nested'
);
echo "OK\n";
