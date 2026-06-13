<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\preg_replace_callback_array;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** preg_replace_callback_array() VM smoke (#3568). */
final class PregReplaceCallbackArrayBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$out = preg_replace_callback_array(
    ['/\d+/' => fn(array $m): string => '[' . $m[0] . ']'],
    'a1b2'
);
echo $out, "\n";
PHP;

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame("a[1]b[2]\n", $this->runBin('bin/vm.php'));
    }

    public function testEnumCaseSubjectThrowsTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new preg_replace_callback_array();
        $enumClass = new \PHPCompiler\VM\ClassEntry('E');
        $enumClass->isEnum = true;
        $enumClass->backedType = 'string';

        $backing = new VMVariable();
        $backing->string('x');
        $case = EnumCaseSupport::createCase($enumClass, 'A', $backing);

        $patterns = new VMVariable();
        $ht = new HashTable();
        $patternKey = new VMVariable();
        $patternKey->string('/x/');
        $callback = new VMVariable();
        $callback->string('strlen');
        $ht->addNew('/x/', $callback);
        $patterns->array($ht);

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            'preg_replace_callback_array(): Argument #2 ($subject) must be of type array|string, E given'
        );
        $this->runBuiltin($fn, $runtime, $patterns, $case);
    }

    private function runBin(string $bin): string
    {
        return $this->runBinWithSource($bin, self::CODE);
    }

    private function runBinWithSource(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_preg_replace_cba_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $err));

        return $this->normalize((string) $out);
    }

    private function runBuiltin(
        Internal $fn,
        Runtime $runtime,
        VMVariable $patterns,
        VMVariable $subject
    ): void {
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->vmContext = $runtime->vmContext;
        $frame->calledArgs = [$patterns, $subject];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
    }

    private function normalize(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }
}
