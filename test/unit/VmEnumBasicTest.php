<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

final class VmEnumBasicTest extends TestCase
{
    public function testBackedEnumDeclareAndCaseFetch(): void
    {
        $code = <<<'PHP'
<?php
enum Status: string {
    case Active = 'active';
}
echo Status::Active;
echo enum_exists('Status') ? '1' : '0';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_basic.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('active1', $output);
        $ctx = $runtime->vmContext;
        $this->assertInstanceOf(Context::class, $ctx);
        $this->assertTrue(VmReflection::enumExists($ctx, 'Status'));
        $this->assertTrue(isset($ctx->classes['status']));
        $this->assertTrue($ctx->classes['status']->isEnum);
        $this->assertSame('string', $ctx->classes['status']->backedType);
        $active = $ctx->classes['status']->constants['active'] ?? null;
        $this->assertNotNull($active);
        $this->assertSame('active', $active->toString());
        $this->assertFalse(VmReflection::classExists($ctx, 'Status'));
    }

    public function testEnumCasesBackedAndUnit(): void
    {
        $code = <<<'PHP'
<?php
enum Suit: string {
    case Hearts = 'H';
    case Diamonds = 'D';
}
enum Status {
    case Pending;
    case Done;
}
$cases = Suit::cases();
echo count($cases);
echo $cases[0]->name;
echo $cases[1]->value;
$unit = Status::cases();
echo count($unit);
echo $unit[0]->name;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_cases.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('2HeartsD2Pending', $output);
        $ctx = $runtime->vmContext;
        $this->assertInstanceOf(Context::class, $ctx);
        $this->assertCount(2, $ctx->classes['suit']->enumCases);
        $this->assertSame('Hearts', $ctx->classes['suit']->enumCases[0]['name']);
        $this->assertSame('D', $ctx->classes['suit']->enumCases[1]['value']->toString());
    }
}
