<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on exit/abort ABI shells from Builtin\Type (#33267).
 *
 * LibcExtern::ensureExitAbort owns module-local decls (getNamedFunction first) so
 * leftover Type empty decls cannot mint exit.1 / abort.1 (#31894 / #32122).
 * User-script exit()/die() stay ScriptExit; NestedJIT ErrorRaise / TypeErrorRaise /
 * ReadonlyRaise call ensureExitAbort before lookup (stdlib.h).
 */
final class TypeDeadExitAbortAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnExitAbortAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33267', $type);
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
        $this->assertStringNotContainsString(
            "registerFunction('exit'",
            $type,
            'Builtin\\Type must not always-register exit (#33267)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('abort'",
            $type,
            'Builtin\\Type must not always-register abort (#33267)'
        );
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        // Last Type always-on shells dropped — no further leftover sentinel.
        $this->assertDoesNotMatchRegularExpression(
            '/\$this->context->module->addFunction\(/',
            $type,
            'Builtin\\Type must not always-on addFunction after exit/abort drop (#33267)'
        );
    }

    public function testLibcExternOwnsExitAbortModuleLocally(): void
    {
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('#33267', $libc);
        $this->assertStringContainsString('function ensureExitAbort', $libc);
        $this->assertStringContainsString('getNamedFunction', $libc);
        $this->assertStringContainsString("'exit'", $libc);
        $this->assertStringContainsString("'abort'", $libc);
    }

    public function testScriptExitEnsuresBeforeLookup(): void
    {
        $script = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ScriptExit.php');
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $script);
        $this->assertStringContainsString('lookupFunction(\'exit\')', $script);
    }

    public function testErrorRaiseFamilyUsesLibcExternExitAbort(): void
    {
        foreach (['ErrorRaise.php', 'TypeErrorRaise.php', 'ReadonlyRaise.php'] as $file) {
            $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/'.$file);
            $this->assertStringContainsString(
                'LibcExtern::ensureExitAbort',
                $src,
                $file.' must call LibcExtern::ensureExitAbort (#33267)'
            );
            $this->assertStringNotContainsString(
                "'exit',\n            \$context->context->functionType(\$void",
                $src,
                $file.' must not locally declare exit ABI (#33267)'
            );
        }
    }

    public function testNoNewRuntimeCForExitAbortAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/exit.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/exit.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/abort.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/abort.c');
    }
}
