<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Runtime;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\JIT\Variable;
use PHPCompiler\ext\standard\IncludeBindingJitHelper;

/**
 * Nested layout→partial include bindings prefer live include-bound strings (#22845).
 */
final class IncludeBindingNestedAppNameTest extends TestCase
{
    public function testIncludeBoundStringOutscoresDefiningTuValueSlot(): void
    {
        $valueSlot = $this->scoreProbe(Variable::TYPE_VALUE, Variable::KIND_VARIABLE, false);
        $includeBound = $this->scoreProbe(Variable::TYPE_STRING, Variable::KIND_VARIABLE, true);

        $this->assertGreaterThan(
            IncludeBindingJitHelper::includeCallerBindingScore($valueSlot),
            IncludeBindingJitHelper::includeCallerBindingScore($includeBound),
            'nested partials must prefer live include-bound $appName over method __value__ slots'
        );
    }

    public function testInheritScopeFromRemapsCollidingSlotsForIncludes(): void
    {
        $runtime = new Runtime();
        $layout = $runtime->parseAndCompileFile(
            dirname(__DIR__, 2).'/examples/003-MiniWebApp/templates/layout.php'
        );
        $home = $runtime->parseAndCompileFile(
            dirname(__DIR__, 2).'/examples/003-MiniWebApp/templates/home.php'
        );
        $this->assertNotNull($layout);
        $this->assertNotNull($home);

        $home->inheritScopeFrom($layout, true);

        $bySlot = [];
        foreach ($home->scopedOperands() as $op) {
            $slot = $home->slotForOperand($op);
            $this->assertNotNull($slot);
            $bySlot[$slot][] = OperandName::resolve($op) ?? 'tmp';
        }
        foreach ($bySlot as $slot => $names) {
            $this->assertCount(
                1,
                $names,
                'include inherit must not leave colliding scope slots (slot '.$slot.')'
            );
        }
    }

    public function testIncludeHelperUsesImmediateCallerNotGrandparent(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/IncludeHelper.php');
        $this->assertStringContainsString('Remap colliding parent slots', $source);
        // Default remaps; PHP_COMPILER_INCLUDE_SCOPE_REMAP=0 opts out for Zend spine pace (#22642).
        $this->assertStringContainsString('$remapCollidingSlots = true', $source);
        $this->assertStringContainsString(
            'inheritScopeFrom($callerBlock, $remapCollidingSlots)',
            $source
        );
        $this->assertStringNotContainsString(
            'count($context->inlineIncludeCallerBlocks) - 2',
            $source
        );
    }

    private function scoreProbe(int $type, int $kind, bool $includeBinding): Variable
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
        $var->includeBinding = $includeBinding;

        return $var;
    }
}
