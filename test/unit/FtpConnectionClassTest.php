<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #7270 */
final class FtpConnectionClassTest extends TestCase
{
    use BuiltinStubEnumTestSkip;

    /** @var false|string */
    private $savedProfile;

    protected function setUp(): void
    {
        $this->savedProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
    }

    protected function tearDown(): void
    {
        if (false === $this->savedProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->savedProfile);
        }
    }

    public function testFtpConnectionBuiltinClassExists(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('FTP\\Connection', false));
echo "\n";
echo (new ReflectionClass('FTP\\Connection'))->getName(), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'ftp_connection_class.php'));
        $this->assertSame("true\nFTP\\Connection\n", ob_get_clean());
    }
}
