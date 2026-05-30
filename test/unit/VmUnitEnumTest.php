<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

final class VmUnitEnumTest extends TestCase
{
    public function testUnitEnumDeclareAndCaseNameFetch(): void
    {
        $code = <<<'PHP'
<?php
enum E {
    case A;
}
echo E::A->name;
echo enum_exists('E') ? '1' : '0';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'unit_enum.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('A1', $output);
        $ctx = $runtime->vmContext;
        $this->assertInstanceOf(Context::class, $ctx);
        $this->assertTrue(VmReflection::enumExists($ctx, 'E'));
        $entry = $ctx->classes['e'];
        $this->assertTrue($entry->isEnum);
        $this->assertNull($entry->backedType);
        $case = $entry->constants['a'] ?? null;
        $this->assertNotNull($case);
        $this->assertSame(Variable::TYPE_OBJECT, $case->type);
        $this->assertTrue(EnumCaseSupport::isEnumCase($case->toObject()));
        $this->assertSame('A', $case->toObject()->enumCaseName);
    }
}
