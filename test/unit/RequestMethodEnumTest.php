<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** RequestMethod phantom retirement (#28931, re-#7230). */
final class RequestMethodEnumTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        parent::tearDown();
    }

    public function testRequestMethodPhantomAbsentOnProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('RequestMethod', false));
echo "\n";
var_export(class_exists('RequestMethod', false));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'requestmethod_phantom.php'));
        $this->assertSame("false\nfalse\n", ob_get_clean());
    }
}
