<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class IteratorApplyInlineArgsTest extends TestCase
{
    public function testInlineArrayThirdArgument(): void
    {
        $code = <<<'PHP'
<?php
class C implements Iterator {
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 2; }
    public function current(): int { return $this->i; }
    public function key(): int { return $this->i; }
    public function next(): void { ++$this->i; }
}
$o = new C();
echo iterator_apply($o, fn ($v) => $v + 1, [$o]), "\n";
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'iterator_apply_inline.php'));
        self::assertSame("2\n", ob_get_clean());
    }
}
