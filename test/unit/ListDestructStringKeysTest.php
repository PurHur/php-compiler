<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Runtime;

/** list() numeric destruct on string-key arrays warns per slot (#4841). */
final class ListDestructStringKeysTest extends TestCase
{
    public function testVmWarnsAndContinuesForStringKeyedArray(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
list($a, $b) = ['x' => 1, 'y' => 2];
echo $a === null ? 'null' : $a;
echo $b === null ? 'null' : $b;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'list_destruct_string_keys.php'));
        self::assertSame('nullnull', ob_get_clean());
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
