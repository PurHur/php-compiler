<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on exit/abort ABI shells from Builtin\Type (#33267 / #35428).
 *
 * NestedJIT/AOT libc decls stay LibcExtern::ensureExitAbort (lazy via Context::lookupFunction
 * after #35428); user-script exit()/die() stay ScriptExit (php-src Zend/zend_builtin_functions.c).
 * Owner declares module-locally (getNamedFunction first) so leftover Type empty decls cannot
 * mint exit.1 / abort.1 (#31894 / #32122).
 */
final class TypeDeadExitAbortAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnExitAbortAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33267', $type);
        $this->assertStringContainsString('#35428', $type);
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
        $regPos = strpos($type, 'public function register(): void');
        $this->assertNotFalse($regPos);
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($initPos);
        $regBody = substr($type, $regPos, $initPos - $regPos);
        $this->assertStringNotContainsString(
            'ensureExitAbort($this->context)',
            $regBody,
            'Type::register must not always-on ensureExitAbort (#35428)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringNotContainsString("addFunction('exit'", $type);
    }

    public function testRuntimeOwnerDeclaresExitAbortAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('#33267', $owner);
        $this->assertStringContainsString('#35428', $owner);
        $this->assertStringContainsString('ensureExitAbort', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString("'exit'", $owner);
        $this->assertStringContainsString("'abort'", $owner);

        $scriptExit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ScriptExit.php');
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $scriptExit);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $ctx);
    }

    public function testNoNewRuntimeCForExitAbortAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/exit.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/exit.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/abort.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/abort.c');
    }
}
