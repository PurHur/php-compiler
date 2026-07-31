<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * intval()/settype() TypeError names concrete class (#25724, zend_API.c / ext/standard/type.c).
 */
final class IntvalSettypeTypeErrorClassNameTest extends TestCase
{
    private const CODE = <<<'PHP'
<?php
try { intval('10', new stdClass); echo "no\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { $x = 1; settype($x, new stdClass); echo "no\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { intval('10', new DateTime('now')); echo "no\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
try { $x = 1; settype($x, new DateTime('now')); echo "no\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
PHP;

    private const EXPECT = "intval(): Argument #2 (\$base) must be of type int, stdClass given\n"
        . "settype(): Argument #2 (\$type) must be of type string, stdClass given\n"
        . "intval(): Argument #2 (\$base) must be of type int, DateTime given\n"
        . "settype(): Argument #2 (\$type) must be of type string, DateTime given\n";

    public function testVmObjectOperandsNameConcreteClass(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    public function testJitObjectOperandsNameConcreteClass(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/jit.php'));
    }

    public function testReproFilePresent(): void
    {
        $root = dirname(__DIR__);
        $this->assertFileExists($root.'/repro/intval_base_typeerror_class_name.php');
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