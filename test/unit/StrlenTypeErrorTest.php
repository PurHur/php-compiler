<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * strlen()/substr()/strpos() array/object TypeError (#4622, ext/standard/string.c).
 */
final class StrlenTypeErrorTest extends TestCase
{
    private const CODE = <<<'PHP'
<?php
try { strlen([]); echo "no-ex\n"; } catch (TypeError $e) { echo "te_strlen\n"; echo $e->getMessage(), "\n"; }
try { substr([], 0); echo "no-ex\n"; } catch (TypeError $e) { echo "te_substr\n"; echo $e->getMessage(), "\n"; }
try { strpos([], 'x'); echo "no-ex\n"; } catch (TypeError $e) { echo "te_strpos\n"; echo $e->getMessage(), "\n"; }
try { strlen(new stdClass()); echo "no-ex\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
echo strlen('abc'), "\n";
echo strlen(42), "\n";
echo substr('abcdef', 1, 2), "\n";
echo strpos('abcdef', 'cd'), "\n";
PHP;

    private const EXPECT = "te_strlen\n"
        . "strlen(): Argument #1 (\$string) must be of type string, array given\n"
        . "te_substr\n"
        . "substr(): Argument #1 (\$string) must be of type string, array given\n"
        . "te_strpos\n"
        . "strpos(): Argument #1 (\$haystack) must be of type string, array given\n"
        . "strlen(): Argument #1 (\$string) must be of type string, stdClass given\n"
        . "3\n"
        . "2\n"
        . "bc\n"
        . "2\n";

    public function testVmArrayObjectOperandsMatchZend(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    public function testJitArrayObjectOperandsMatchZend(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/jit.php'));
    }

    public function testCompliancePhptPresent(): void
    {
        $root = dirname(__DIR__);
        $this->assertFileExists($root.'/compliance/cases/stdlib/strlen_type_error.phpt');
        $this->assertFileExists($root.'/compliance/cases/stdlib/strlen_type_error_jit.phpt');
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $proc = proc_open(
            ['php', $repo.'/'.$bin],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fwrite($pipes[0], self::CODE);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim((string) $err));

        return preg_replace('/\r\n?/', "\n", (string) $out) ?? '';
    }
}
