<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\UsortRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * UsortJitHelper NestedJIT must lower Variable::duplicateFrom and rebuild via append (#24142).
 *
 * @group aot-lint
 */
final class UsortJitHelperNestedJitTest extends TestCase
{
    public function testUsortJitHelperNestedCompileAcceptsDuplicateFrom(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        foreach (['add', 'addindex', 'updateindex', 'append', 'iterate', 'exportkeyvaluepairs', 'getnumelements', 'find', 'findindex'] as $htMethod) {
            NestedVmHashTableMethodLlvm::ensureMethod($ctx, $htMethod);
        }
        foreach (['null', 'int', 'string', 'array', 'copyfrom', 'duplicatefrom', 'resolveindirect', 'toint', 'tostring'] as $method) {
            NestedVmVariableMethodLlvm::ensureMethod($ctx, $method);
        }
        NestedVmActiveContextLlvm::ensureMethod($ctx);

        NestedJitCompileScope::run($ctx, static function () use ($ctx, $runtime): void {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
            $jit = new \PHPCompiler\JIT($ctx);
            $path = __DIR__.'/../../../ext/standard/UsortJitHelper.php';
            $block = $runtime->parseAndCompile((string) file_get_contents($path), 'UsortJitHelper.php');
            self::assertNotNull($block);
            $jit->compile($block);
        });

        UsortRuntime::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__usort__packed_closure');
        self::assertNotNull($fn);
        self::assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
