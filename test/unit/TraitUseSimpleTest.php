<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Simple `use Trait;` in class body (issue #2314). */
final class TraitUseSimpleTest extends TestCase
{
    public function testVmTraitUseMergesMethod(): void
    {
        $code = <<<'PHP'
<?php
trait Greets {
    public function greet(): string {
        return 'hello';
    }
}
class Speaker {
    use Greets;
}
echo (new Speaker())->greet();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'trait_use.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('hello', ob_get_clean());
    }
}
