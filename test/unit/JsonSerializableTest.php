<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmJson;
use PHPUnit\Framework\TestCase;

/** JsonSerializable json_encode export (issue #3370). */
final class JsonSerializableTest extends TestCase
{
    public function testJsonEncodeInvokesJsonSerialize(): void
    {
        $source = <<<'PHP'
<?php
class Payload implements JsonSerializable {
    public function jsonSerialize(): mixed {
        return ['id' => 1];
    }
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($source, 'json_serializable.php');
        self::assertNotNull($block);
        $runtime->run($block);

        $ctx = $runtime->vmContext;
        $vm = $runtime->vm;
        self::assertNotNull($vm);

        $class = $ctx->classes['payload'];
        $object = new ObjectEntry($class);
        $object->constructed = true;

        $var = new Variable();
        $var->object($object);

        $exported = VmJson::export($var, $ctx, $vm);
        self::assertSame(['id' => 1], $exported);
    }

    public function testNonJsonSerializableObjectSetsUnsupportedTypeError(): void
    {
        $source = <<<'PHP'
<?php
class Plain {}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($source, 'plain.php');
        self::assertNotNull($block);
        $runtime->run($block);

        $ctx = $runtime->vmContext;
        $vm = $runtime->vm;
        self::assertNotNull($vm);

        $class = $ctx->classes['plain'];
        $object = new ObjectEntry($class);
        $object->constructed = true;

        try {
            $var = new Variable();
            $var->object($object);
            VmJson::export($var, $ctx, $vm);
            self::fail('Expected VmJsonExportException');
        } catch (\PHPCompiler\ext\standard\VmJsonExportException $e) {
            self::assertSame(VmJson::ERROR_UNSUPPORTED_TYPE, $e->errorCode);
            self::assertSame(VmJson::ERROR_UNSUPPORTED_TYPE, VmJson::lastError());
        }
    }
}
