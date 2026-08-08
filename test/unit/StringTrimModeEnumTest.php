<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** StringTrimMode phantom retirement — trim stays arity ≤2 (#28202 / #28230, re-#7283). */
final class StringTrimModeEnumTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        parent::tearDown();
    }

    public function testStringTrimModePhantomAbsentAndArity(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('StringTrimMode', false));
echo "\n";
echo trim('  x  '), "\n";
try {
    trim(' x ', ' ', true);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'string_trim_mode_no_enum.php'));
        $this->assertSame(
            "false\nx\nArgumentCountError:trim() expects at most 2 arguments, 3 given\n",
            ob_get_clean()
        );
    }
}
