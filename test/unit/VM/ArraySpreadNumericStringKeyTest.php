<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Zend array spread: numeric-string keys renumber (#5072). */
final class ArraySpreadNumericStringKeyTest extends TestCase
{
    public function testSpreadGeneratorNumericStringKeyRenumbers(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class G implements IteratorAggregate {
    public function getIterator(): Traversable {
        yield "0" => "s";
    }
}
$d = [0 => "i", ...new G()];
echo count($d), "\n", $d[0], ",", $d[1], "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'array_spread_numeric_string_key.php'));
        $output = ob_get_clean();
        $this->assertIsString($output);
        $this->assertSame("2\ni,s\n", $output);
    }
}
