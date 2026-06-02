<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Runtime;

/** list() / [] destructuring must reject non-list arrays (#4298). */
final class ListDestructStringKeysTest extends TestCase
{
    public function testVmRejectsStringKeyedArray(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
list($a, $b) = ['x' => 1, 'y' => 2];
PHP;
        try {
            $runtime->run($runtime->parseAndCompile($code, 'list_destruct_string_keys.php'));
            self::fail('expected TypeError');
        } catch (\TypeError $e) {
            self::assertSame('Cannot unpack array with string keys', $e->getMessage());
        }
    }

    public function testVmAcceptsPackedList(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
list($a, $b) = [1, 2];
echo $a, $b;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'list_destruct_packed.php'));
        self::assertSame('12', ob_get_clean());
    }
}
