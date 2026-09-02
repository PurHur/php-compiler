<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable;

/** @group aot-lint */
final class CompileTimeStringFoldSafetyTest extends TestCase
{
    public function testCompileTimeLiteralForFoldRejectsSlotBackedString(): void
    {
        $var = $this->makeStringVar(Variable::KIND_VARIABLE, 'hello');

        $this->assertSame('hello', JitStringArg::compileTimeLiteral($var));
        $this->assertNull(JitStringArg::compileTimeLiteralForFold($var));
    }

    public function testCompileTimeLiteralForFoldAcceptsImmediateString(): void
    {
        $var = $this->makeStringVar(Variable::KIND_VALUE, 'hello');

        $this->assertSame('hello', JitStringArg::compileTimeLiteralForFold($var));
    }

    private function makeStringVar(int $kind, ?string $literal): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::TYPE_STRING);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, $kind);
        $var->compileTimeString = $literal;

        return $var;
    }
}
