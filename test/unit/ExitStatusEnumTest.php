<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\ScriptExit;
use PHPUnit\Framework\TestCase;

/** ExitStatus phantom retirement — exit()/die() stay string|int (#28200, re-#7294). */
final class ExitStatusEnumTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        parent::tearDown();
    }

    public function testExitStatusPhantomAbsentOnProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('ExitStatus', false));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'exit_status_no_enum.php'));
        $this->assertSame("false\n", ob_get_clean());
    }

    public function testExitIntStatusUnchanged(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile('<?php exit(1);', 'exit_int.php'));
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame(1, $e->status);
        }
        $this->assertSame('', ob_get_clean());
    }

    public function testDieIntStatusUnchanged(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile('<?php die(0);', 'die_int.php'));
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame('', ob_get_clean());
    }
}
