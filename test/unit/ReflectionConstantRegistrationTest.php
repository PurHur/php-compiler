<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

final class ReflectionConstantRegistrationTest extends TestCase
{
    public function testReflectionConstantRegisteredOnForwardProfile(): void
    {
        if (!CompilerVersion::advertisesReflectionConstantClass()) {
            $this->markTestSkipped('ReflectionConstant not advertised on reference profile');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('ReflectionConstant', false));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_constant_exists.php'));
        $out = ob_get_clean();

        $this->assertSame('true', trim($out));
    }
}
