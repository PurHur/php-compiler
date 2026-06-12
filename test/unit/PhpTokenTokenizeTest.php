<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * PhpToken::tokenize() VM + AOT lint (#6794).
 *
 * @group phptoken_tokenize
 */
final class PhpTokenTokenizeTest extends TestCase
{
    public function testPhpTokenTokenizeVmRepro(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo method_exists('PhpToken', 'tokenize') ? "yes\n" : "no\n";
$tokens = PhpToken::tokenize('<?php echo 1;');
echo $tokens[1]->id, "\n";
echo $tokens[1]->text, "\n";
echo $tokens[1]->getTokenName(), "\n";
echo $tokens[1]->is(T_ECHO) ? "is\n" : "not\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'phptoken_tokenize.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("yes\n291\necho\nT_ECHO\nis\n", ob_get_clean());
    }

    public function testPhpTokenTokenizeAotLint(): void
    {
        $root = \dirname(__DIR__, 2);
        $probe = $root.'/test/fixtures/aot/compile-only/phptoken_tokenize.php';
        self::assertFileExists($probe);
        $cmd = 'php bin/compile.php -l '.escapeshellarg($probe);
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for PhpToken::tokenize probe (#6794)'
        );
    }
}
