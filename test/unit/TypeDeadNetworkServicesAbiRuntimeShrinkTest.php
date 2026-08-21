<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on network-services compiler ABI shells from Builtin\Type (#32701).
 *
 * User-script getprotobynumber()/getservbyport()/getprotobyname()/getservbyname() stay
 * NetworkServicesJitHelper / NetworkServicesNameLookupJitHelper / JitNetworkServices.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadNetworkServicesAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_getprotobynumber',
            '__compiler_getservbyport',
            '__phpc_getprotobyname',
            '__phpc_getservbyname',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnNetworkServicesAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32701', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32701)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32701)"
            );
        }
        // No further Type always-on leftover after exit/abort drop (#33267).
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]exit[\'"]/',
            $type,
            'Builtin\\Type must not always-declare exit (#33267)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]abort[\'"]/',
            $type,
            'Builtin\\Type must not always-declare abort (#33267)'
        );
        $this->assertStringContainsString("registerFunction('__compiler_http_build_query'", $type);
    }

    public function testRuntimeOwnersDeclareNetworkServicesAbisModuleLocally(): void
    {
        $stringReturn = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNetworkServicesStringReturn.php');
        $this->assertStringContainsString('__compiler_getprotobynumber', $stringReturn);
        $this->assertStringContainsString('__compiler_getservbyport', $stringReturn);
        $this->assertStringContainsString("getNamedFunction('__compiler_getprotobynumber')", $stringReturn);
        $this->assertStringContainsString('getNamedFunction($abiName)', $stringReturn);
        $this->assertStringContainsString('#32701', $stringReturn);

        $nameLookup = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNetworkServicesNameLookup.php');
        $this->assertStringContainsString('__phpc_getprotobyname', $nameLookup);
        $this->assertStringContainsString('__phpc_getservbyname', $nameLookup);
        $this->assertStringContainsString("getNamedFunction('__phpc_getprotobyname')", $nameLookup);
        $this->assertStringContainsString("getNamedFunction('__phpc_getservbyname')", $nameLookup);
    }

    public function testNestedJitConsumersEnsureRuntimeBeforeLookup(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitNetworkServices.php');
        $this->assertStringContainsString('StringNetworkServices::ensureLinked', $jit);
        $this->assertStringContainsString('StringNetworkServices::ensureStringReturnLinked', $jit);
        $this->assertStringContainsString("lookupFunction('__phpc_getprotobyname')", $jit);
        $this->assertStringContainsString("lookupFunction('__phpc_getservbyname')", $jit);
        $this->assertStringContainsString("lookupFunction('__compiler_getprotobynumber')", $jit);
        $this->assertStringContainsString("lookupFunction('__compiler_getservbyport')", $jit);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'getprotobynameLookup',
            (string) file_get_contents(__DIR__.'/../../ext/standard/NetworkServicesNameLookupJitHelper.php')
        );
        $this->assertStringContainsString(
            'getservbynameLookup',
            (string) file_get_contents(__DIR__.'/../../ext/standard/NetworkServicesNameLookupJitHelper.php')
        );
        $this->assertStringContainsString(
            'getprotobynumber',
            (string) file_get_contents(__DIR__.'/../../ext/standard/NetworkServicesJitHelper.php')
        );
        $this->assertStringContainsString(
            'getservbyport',
            (string) file_get_contents(__DIR__.'/../../ext/standard/NetworkServicesJitHelper.php')
        );
    }
}
