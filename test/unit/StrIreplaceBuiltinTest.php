<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\str_ireplace;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM/AOT smoke for str_ireplace(). */
final class StrIreplaceBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
echo str_ireplace('O', '0', 'fOo'), "\n";
echo str_ireplace('AB', 'X', 'cab AbC'), "\n";
echo str_ireplace('z', '', 'no match'), "\n";
PHP;

    private const EXPECT = <<<'TXT'
f00
cX XC
no match
TXT;

    public function testCaseInsensitiveReplace(): void
    {
        $runtime = new Runtime();
        $fn = new str_ireplace();
        $frame = $fn->getFrame($runtime->vmContext);
        $search = new VMVariable();
        $search->string('Ab');
        $replace = new VMVariable();
        $replace->string('X');
        $subject = new VMVariable();
        $subject->string('cab AbC');
        $frame->calledArgs = [$search, $replace, $subject];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame('cX XC', $frame->returnVar->resolveIndirect()->toString());
    }

    public function testArraySearchReplacesAllNeedles(): void
    {
        $runtime = new Runtime();
        $fn = new str_ireplace();
        $frame = $fn->getFrame($runtime->vmContext);
        $search = new VMVariable();
        $searchHt = new HashTable();
        $searchHt->append($this->stringVar('a'));
        $searchHt->append($this->stringVar('b'));
        $search->array($searchHt);
        $replace = new VMVariable();
        $replace->string('X');
        $subject = new VMVariable();
        $subject->string('AbBa');
        $count = new VMVariable();
        $count->int(0);
        $frame->calledArgs = [$search, $replace, $subject, $count];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame('XXXX', $frame->returnVar->resolveIndirect()->toString());
        $this->assertSame(4, $count->resolveIndirect()->toInt());
    }

    private function stringVar(string $value): VMVariable
    {
        $var = new VMVariable();
        $var->string($value);

        return $var;
    }

    public function testEmptySearchThrows(): void
    {
        $runtime = new Runtime();
        $fn = new str_ireplace();
        $frame = $fn->getFrame($runtime->vmContext);
        $search = new VMVariable();
        $search->string('');
        $replace = new VMVariable();
        $replace->string('x');
        $subject = new VMVariable();
        $subject->string('foo');
        $frame->calledArgs = [$search, $replace, $subject];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertSame('foo', $frame->returnVar->toString());
    }

    public function testVmScriptMatchesSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    /**
     * @group llvm
     */
    public function testAotNativeBinaryMatchesSubset(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::EXPECT, $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_str_ireplace_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            [PHP_BINARY, $repo.'/bin/compile.php', '-o', $out, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), trim((string) $compileErr));
        $run = proc_open(
            [$out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $runPipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $result = stream_get_contents($runPipes[1]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $this->assertSame(0, proc_close($run));
        @unlink($tmp);
        @unlink($out);

        return $this->normalize((string) $result);
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_str_ireplace_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open([PHP_BINARY, $path, $tmp], $descriptor, $pipes, $repo, $env);
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

    private function normalize(string $text): string
    {
        return preg_replace('/\r\n?/', "\n", trim($text)) ?? '';
    }
}
