<?php

/**
 * Host/Docker probe for #24217 — NestedJIT must not silent-null substr()/strlen().
 *
 * php test/repro/issue_24217_nested_substr_probe.php
 */
require __DIR__.'/../../vendor/autoload.php';

use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\Runtime;

$runtime = new Runtime(Runtime::MODE_AOT);
$ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
foreach (['add', 'addindex', 'updateindex', 'append', 'iterate', 'iteratekeyed', 'exportkeyvaluepairs', 'getnumelements'] as $htMethod) {
    NestedVmHashTableMethodLlvm::ensureMethod($ctx, $htMethod);
}
foreach (['null', 'int', 'string', 'array', 'copyfrom', 'resolveindirect'] as $method) {
    NestedVmVariableMethodLlvm::ensureMethod($ctx, $method);
}
NestedVmActiveContextLlvm::ensureMethod($ctx);

$path = __DIR__.'/../unit/JIT/fixtures/Issue24217SubstrJitHelper.php';
NestedJitCompileScope::run($ctx, static function () use ($ctx, $runtime, $path): void {
    putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
    putenv('PHP_COMPILER_SELFHOST_AOT=0');
    $jit = new PHPCompiler\JIT($ctx);
    $block = $runtime->parseAndCompile((string) file_get_contents($path), 'Issue24217SubstrJitHelper.php');
    if (null === $block) {
        fwrite(STDERR, "parseAndCompile failed\n");
        exit(2);
    }
    $jit->compile($block);
});

$ok = true;
foreach (['substr', 'strlen', 'phpcompiler\\ext\\standard\\substr'] as $name) {
    $proxy = $ctx->resolveFunctionProxy($name);
    $kind = get_class($proxy);
    $bad = $proxy instanceof ExternalMethod || !($proxy instanceof FuncInternal);
    echo ($bad ? 'FAIL' : 'ok')." {$name}: {$kind}\n";
    $ok = $ok && !$bad;
}

$hits = [];
foreach (array_keys($ctx->externalMethodStubs) as $stub) {
    if (str_contains($stub, 'substr') || preg_match('/(^|\\\\)strlen$/', $stub)) {
        $hits[] = $stub;
    }
}
if ([] !== $hits) {
    echo 'FAIL stub_hits: '.implode(', ', $hits)."\n";
    $ok = false;
} else {
    echo "ok stub_hits: (none)\n";
}

exit($ok ? 0 : 1);
