<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

final class ReflectionConstantRegistrationTest extends TestCase
{
    public function testReflectionConstantAbsentOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesReflectionConstantClass());

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('ReflectionConstant', false));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_constant_absent.php'));
        $out = ob_get_clean();

        $this->assertSame('false', trim($out));
    }

    public function testReflectionConstantRegisteredOnForwardProfile83(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantClass());

            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
var_export(class_exists('ReflectionConstant', false));
echo "\n";
define('FOO_RC_25504', 99);
$ref = new ReflectionConstant('FOO_RC_25504');
echo $ref->getName(), '=', $ref->getValue(), "\n";
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'reflection_constant_exists_83.php'));
            $out = ob_get_clean();

            $this->assertSame("true\nFOO_RC_25504=99", trim($out));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testReflectionConstantRegisteredOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesReflectionConstantClass());

            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
var_export(class_exists('ReflectionConstant', false));
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'reflection_constant_exists.php'));
            $out = ob_get_clean();

            $this->assertSame('true', trim($out));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
