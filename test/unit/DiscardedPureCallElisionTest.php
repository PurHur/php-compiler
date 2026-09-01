<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\types\strlen;
use PHPCompiler\JIT\DiscardedPureCallElision;
use PHPCompiler\JIT\Variable;

/** @group aot-lint */
final class DiscardedPureCallElisionTest extends TestCase
{
    public function testElidesDiscardedStrlenWithCompileTimeString(): void
    {
        $builtin = new strlen();
        $arg = $this->makeStringVar('hallo');

        $this->assertTrue(DiscardedPureCallElision::tryElide($builtin, [$arg]));
    }

    public function testDoesNotElideStrlenWithoutCompileTimeOperand(): void
    {
        $builtin = new strlen();
        $arg = $this->makeStringVar(null);

        $this->assertFalse(DiscardedPureCallElision::tryElide($builtin, [$arg]));
    }

    public function testJitWiresElisionBeforeInvoke(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');

        $this->assertStringContainsString('DiscardedPureCallElision::tryElide', $source);
        $this->assertStringContainsString('TYPE_FUNCCALL_EXEC_NORETURN', $source);
    }

    private function makeStringVar(?string $literal): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::TYPE_STRING);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, Variable::KIND_VARIABLE);
        $var->compileTimeString = $literal;

        return $var;
    }
}
