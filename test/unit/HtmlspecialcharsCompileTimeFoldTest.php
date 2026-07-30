<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\JIT\Variable;
use ReflectionMethod;

/**
 * htmlspecialchars() must fold TYPE_STRING + KIND_VARIABLE when compileTimeString is set (#25345).
 */
final class HtmlspecialcharsCompileTimeFoldTest extends TestCase
{
    public function testFoldableStringAcceptsKindVariableTypeString(): void
    {
        $ref = new ReflectionMethod(
            \PHPCompiler\ext\standard\htmlspecialchars::class,
            'isCompileTimeFoldableString'
        );
        $ref->setAccessible(true);

        $var = $this->makeVar(Variable::TYPE_STRING, Variable::KIND_VARIABLE, 'MiniWebApp');
        $this->assertTrue($ref->invoke(null, $var));

        $imm = $this->makeVar(Variable::TYPE_STRING, Variable::KIND_VALUE, 'Home');
        $this->assertTrue($ref->invoke(null, $imm));

        $noCts = $this->makeVar(Variable::TYPE_STRING, Variable::KIND_VARIABLE, null);
        $this->assertFalse($ref->invoke(null, $noCts));

        $boxed = $this->makeVar(Variable::TYPE_VALUE, Variable::KIND_VARIABLE, 'MiniWebApp');
        $this->assertFalse(
            $ref->invoke(null, $boxed),
            'boxed KIND_VARIABLE must not fold from compileTimeString alone'
        );
    }

    public function testSourceDocumentsKindVariableFold(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/htmlspecialchars.php');
        $this->assertStringContainsString('isCompileTimeFoldableString', $source);
        $this->assertStringContainsString('#25345', $source);
        $this->assertStringContainsString('KIND_VARIABLE', $source);
    }

    private function makeVar(int $type, int $kind, ?string $cts): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, $type);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, $kind);
        $var->compileTimeString = $cts;

        return $var;
    }
}
