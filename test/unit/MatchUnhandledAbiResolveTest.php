<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * Compiler ABI helpers stay callable via resolveFunctionCallLc even when hidden
 * from function_exists (#22820 regression after #22796; clone-with #22856).
 */
final class MatchUnhandledAbiResolveTest extends TestCase
{
    public function testPhpcMatchHelperResolvesWhileHiddenFromFunctionExists(): void
    {
        $runtime = new Runtime();
        $ref = new \ReflectionObject($runtime);
        $load = $ref->getMethod('loadCoreModules');
        $load->setAccessible(true);
        $load->invoke($runtime);
        $ctxProp = $ref->getProperty('vmContext');
        $ctxProp->setAccessible(true);
        /** @var \PHPCompiler\VM\Context $ctx */
        $ctx = $ctxProp->getValue($runtime);

        $name = 'phpc_match_unhandled_operand_message';
        $this->assertTrue(isset($ctx->functions[$name]), 'helper must be registered');
        $this->assertFalse(
            VmReflection::isVisibleToFunctionExists($name),
            'helper stays hidden from function_exists introspection'
        );
        $this->assertSame(
            $name,
            $ctx->resolveFunctionCallLc($name),
            'match lowering must still resolve the ABI helper (#22820, #23664)'
        );
        // Legacy probe remains registered for unit coverage / older CFG.
        $legacy = 'phpc_match_unhandled_operand_is_object';
        $this->assertTrue(isset($ctx->functions[$legacy]), 'legacy is_object probe still registered');
        $this->assertSame($legacy, $ctx->resolveFunctionCallLc($legacy));
    }

    /**
     * Clone-with desugar calls phpc_clone_with_{begin,end,reinit}; same ABI-visibility
     * contract as match helpers (#22856, re-#22820).
     */
    public function testPhpcCloneWithHelpersResolveWhileHiddenFromFunctionExists(): void
    {
        $runtime = new Runtime();
        $ref = new \ReflectionObject($runtime);
        $load = $ref->getMethod('loadCoreModules');
        $load->setAccessible(true);
        $load->invoke($runtime);
        $ctxProp = $ref->getProperty('vmContext');
        $ctxProp->setAccessible(true);
        /** @var \PHPCompiler\VM\Context $ctx */
        $ctx = $ctxProp->getValue($runtime);

        foreach (['phpc_clone_with_begin', 'phpc_clone_with_end', 'phpc_clone_with_reinit'] as $name) {
            $this->assertTrue(isset($ctx->functions[$name]), "{$name} must be registered");
            $this->assertFalse(
                VmReflection::isVisibleToFunctionExists($name),
                "{$name} stays hidden from function_exists"
            );
            $this->assertTrue(VmReflection::isCompilerAbiHelperName($name));
            $this->assertSame(
                $name,
                $ctx->resolveFunctionCallLc($name),
                "clone-with lowering must resolve {$name} (#22856)"
            );
        }
    }

    public function testExitStaysUnresolvableOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        $ref = new \ReflectionObject($runtime);
        $load = $ref->getMethod('loadCoreModules');
        $load->setAccessible(true);
        $load->invoke($runtime);
        $ctxProp = $ref->getProperty('vmContext');
        $ctxProp->setAccessible(true);
        /** @var \PHPCompiler\VM\Context $ctx */
        $ctx = $ctxProp->getValue($runtime);

        $this->assertNull($ctx->resolveFunctionCallLc('exit'));
        $this->assertNull($ctx->resolveFunctionCallLc('die'));
    }

    public function testUnhandledMatchThrowsUnhandledMatchErrorNotUndefinedFunction(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php try { match(3){ 1=>"a", 2=>"b" }; } catch (Throwable $e) {'
            .' echo get_class($e), " :: ", $e->getMessage(), "\n"; }',
            'match_unhandled_abi.php'
        );
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame("UnhandledMatchError :: Unhandled match case 3\n", $out);
    }
}
