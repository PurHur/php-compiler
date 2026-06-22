<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Direct VM coverage for __debugInfo() export path (issue #3259). */
final class DebugInfoVmHookTest extends TestCase
{
    public function testGetObjectDebugPropertiesInvokesMagicMethod(): void
    {
        $source = <<<'PHP'
<?php
class C {
    private int $secret = 1;
    public function __debugInfo(): array {
        return ['redacted' => true];
    }
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($source, 'debug_info.php');
        self::assertNotNull($block);
        $runtime->run($block);

        $ctx = $runtime->vmContext;
        $vm = $runtime->vm;
        self::assertNotNull($vm);

        $class = $ctx->classes['c'];
        $object = new ObjectEntry($class);
        $object->constructed = true;

        $props = $vm->getObjectDebugProperties($object);
        self::assertArrayHasKey('redacted', $props);
        self::assertSame(Variable::TYPE_BOOLEAN, $props['redacted']->type);
        self::assertTrue($props['redacted']->toBool());
        self::assertArrayNotHasKey('secret', $props);
    }

    public function testGetObjectDebugPropertiesAcceptsNullReturn(): void
    {
        $source = <<<'PHP'
<?php
class C {
    public function __debugInfo(): ?array {
        return null;
    }
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($source, 'debug_info_null.php');
        self::assertNotNull($block);
        $runtime->run($block);

        $ctx = $runtime->vmContext;
        $vm = $runtime->vm;
        self::assertNotNull($vm);

        $class = $ctx->classes['c'];
        $object = new ObjectEntry($class);
        $object->constructed = true;

        self::assertSame([], $vm->getObjectDebugProperties($object));
    }

    public function testGetObjectDebugPropertiesRejectsNonArrayReturn(): void
    {
        $source = <<<'PHP'
<?php
class C {
    public function __debugInfo() {
        return 'not-array';
    }
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($source, 'debug_info_bad.php');
        self::assertNotNull($block);
        $runtime->run($block);

        $ctx = $runtime->vmContext;
        $vm = $runtime->vm;
        self::assertNotNull($vm);

        $class = $ctx->classes['c'];
        $object = new ObjectEntry($class);
        $object->constructed = true;

        try {
            $vm->getObjectDebugProperties($object);
            self::fail('Expected TypeError for non-array __debugInfo() return');
        } catch (\TypeError $e) {
            self::assertSame(
                'C::__debugInfo(): Return value must be of type array, string returned',
                $e->getMessage()
            );
        }
    }
}
