<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall;
use PHPCompiler\JIT\IteratorProtocolHelper;
use PHPCompiler\JIT\Variable;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Iterator foreach on object-typed containers must use runtime class_id dispatch (#4083).
 *
 * @group llvm
 */
final class ForeachIteratorPolymorphicJitTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — polymorphic Iterator JIT test needs LLVM (#4083)');
        }
    }

    public function testResolveIteratorMethodProxyUsesRuntimeIndirectWhenMultipleIteratorClasses(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class Impl implements Iterator {
    private array $a = [1];
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->a); }
    public function current(): mixed { return $this->a[$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; }
}
class Other implements Iterator {
    private array $a = [2];
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->a); }
    public function current(): mixed { return $this->a[$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; }
}
function run(object $o): void {
    foreach ($o as $v) {
        echo $v;
    }
}
PHP
            ,
            'foreach_iterator_polymorphic_jit.php'
        );
        $this->assertNotNull($block);

        try {
            $runtime->jitCompileBlock($block);
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString(
                'polymorphic object is not supported',
                $e->getMessage(),
                'Iterator foreach on object must not fail polymorphic dispatch at compile time'
            );
        }

        $context = $runtime->loadJitContext();
        $objPtr = $context->getTypeFromString('__object__*')->constNull();
        $receiver = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objPtr);
        $candidates = IteratorProtocolHelper::methodCandidates($context, 'rewind');
        $this->assertGreaterThanOrEqual(
            2,
            count($candidates),
            'compile unit must register multiple Iterator implementors'
        );

        $proxy = IteratorProtocolHelper::resolveIteratorMethodProxy($context, $receiver, 'rewind', 'object');
        $this->assertInstanceOf(RuntimeIndirectInstanceMethodCall::class, $proxy);
        $this->assertSame($candidates, $proxy->candidatesByClassId);
        $this->assertTrue(
            IteratorProtocolHelper::canLowerIteratorProtocol($context, $receiver, 'object')
        );
    }
}
