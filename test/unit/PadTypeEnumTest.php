<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7282 / #28201 — PadType never in php-src */
final class PadTypeEnumTest extends TestCase
{
    public function testPadTypeWithheldOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            self::assertFalse(CompilerVersion::supportsPadTypeEnum());
            $runtime = new Runtime();
            $this->assertFalse(isset($runtime->vmContext->classes['padtype']));
            $code = <<<'PHP'
<?php
var_export(enum_exists('PadType', false));
echo "\n";
echo str_pad('hi', 5, ' ', STR_PAD_RIGHT), "\n";
echo str_pad('hi', 5, ' ', STR_PAD_LEFT), "\n";
echo str_pad('hi', 6, '-', STR_PAD_BOTH), "\n";
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'pad_type_no_enum.php'));
            $this->assertSame("false\nhi   \n   hi\n--hi--\n", ob_get_clean());
        } finally {
            unset($_ENV['PHP_COMPILER_PROFILE']);
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testPadTypeWithheldOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->classes['padtype']));
        self::assertFalse(CompilerVersion::supportsPadTypeEnum());
    }
}
