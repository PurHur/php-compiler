<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on datetime + fs-glob vec ABI shells from Builtin\Type (#32636).
 *
 * User-script localtime()/gmgetdate()/gmmktime()/glob()/scandir() stay PHP helpers.
 * Runtime owners declare module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadDatetimeGlobVecAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_localtime',
            '__compiler_gmgetdate',
            '__compiler_gmmktime',
            '__phpc_glob_vec',
            '__phpc_scandir_vec',
            '__phpc_strvec_free',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnDatetimeGlobVecAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32636', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32636)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32636)"
            );
        }
    }

    public function testRuntimeOwnersDeclareDatetimeGlobVecAbisModuleLocally(): void
    {
        $localtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringLocaltime.php');
        $this->assertStringContainsString('__compiler_localtime', $localtime);
        $this->assertStringContainsString('getNamedFunction(self::ABI_NAME)', $localtime);

        $gmgetdate = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGmgetdate.php');
        $this->assertStringContainsString('__compiler_gmgetdate', $gmgetdate);
        $this->assertStringContainsString('getNamedFunction(self::ABI_NAME)', $gmgetdate);

        $gmmktime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGmmktime.php');
        $this->assertStringContainsString('__compiler_gmmktime', $gmmktime);
        $this->assertStringContainsString("getNamedFunction('__compiler_gmmktime')", $gmmktime);

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFsGlobKernel.php');
        $this->assertStringContainsString('__phpc_glob_vec', $kernel);
        $this->assertStringContainsString('__phpc_scandir_vec', $kernel);
        $this->assertStringContainsString('getNamedFunction($name)', $kernel);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'LocaltimeJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/LocaltimeJitHelper.php')
        );
        $this->assertStringContainsString(
            'GmgetdateJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/GmgetdateJitHelper.php')
        );
        $this->assertStringContainsString(
            'GmmktimeJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/GmmktimeJitHelper.php')
        );
        $this->assertStringContainsString(
            'FsGlobJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/FsGlobJitHelper.php')
        );
    }
}
