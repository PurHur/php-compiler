<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RegexIterator::setMode/setFlags/setPregFlags(null) — soft-null E_DEPRECATED then 0 (#31748).
 *
 * php-src: ext/spl/spl_iterators.c — zim_RegexIterator_setMode|setFlags|setPregFlags
 */
final class Issue31748RegexIteratorSettersNullTest extends TestCase
{
    public function testVmSettersNullDeprecationThenZero(): void
    {
        $out = $this->runBin('bin/vm.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitSettersNullDeprecationThenZero(): void
    {
        $out = $this->runBin('bin/jit.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesSetModeNullTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: RegexIterator::setMode(): Argument #1 (\$mode) must be of type int, null given\n",
            $out
        );
    }

    public function testJitStrictTypesSetModeNullTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: RegexIterator::setMode(): Argument #1 (\$mode) must be of type int, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        return "DEP:RegexIterator::setMode(): Passing null to parameter #1 (\$mode) of type int is deprecated\n"
            ."setMode=0\n"
            ."DEP:RegexIterator::setFlags(): Passing null to parameter #1 (\$flags) of type int is deprecated\n"
            ."setFlags=0\n"
            ."DEP:RegexIterator::setPregFlags(): Passing null to parameter #1 (\$pregFlags) of type int is deprecated\n"
            ."setPregFlags=0\n";
    }

    private function softProbeCode(): string
    {
        return <<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
$it = new RegexIterator(new ArrayIterator(['a1']), '/\d/');
foreach (['setMode' => 'getMode', 'setFlags' => 'getFlags', 'setPregFlags' => 'getPregFlags'] as $set => $get) {
    try {
        $it->$set(null);
        echo $set, '=', $it->$get(), "\n";
    } catch (Throwable $e) {
        echo $set, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$it = new RegexIterator(new ArrayIterator(['a1']), '/\d/');
try {
    $it->setMode(null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31748_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, $stdout.$stderr);

        return $stdout;
    }
}
