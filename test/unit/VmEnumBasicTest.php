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
        $active = $ctx->classes['status']->constants['active'] ?? null;
        $this->assertNotNull($active);
        $this->assertSame('active', $active->toString());
        $this->assertFalse(VmReflection::classExists($ctx, 'Status'));
    }
}
