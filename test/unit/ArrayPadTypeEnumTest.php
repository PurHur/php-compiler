<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #17240 */
final class ArrayPadTypeEnumTest extends TestCase
{
    public function testArrayPadTypeBuiltinEnumOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsArrayPadTypeEnum()) {
                self::markTestSkipped('ArrayPadType withheld on reference profile');
            }
            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->classes['arraypadtype']));
            $code = <<<'PHP'
<?php
var_export(enum_exists('ArrayPadType', false));
echo "\n";
var_export(array_pad([1], 4, 0, ArrayPadType::Positive));
echo "\n";
var_export(array_pad([1], 4, 0, ArrayPadType::Negative));
echo "\n";
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'array_pad_type_enum.php'));
            $this->assertSame(
                "true\narray (\n  0 => 1,\n  1 => 0,\n  2 => 0,\n  3 => 0,\n)\narray (\n  0 => 0,\n  1 => 0,\n  2 => 0,\n  3 => 1,\n)\n",
                ob_get_clean()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testArrayPadTypeWithheldOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->classes['arraypadtype']));
    }
}
