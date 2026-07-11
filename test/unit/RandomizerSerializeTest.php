<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmSerialize;
use PHPUnit\Framework\TestCase;

final class RandomizerSerializeTest extends TestCase
{
    public function testRandomizerClassHasMagicSerializeMethods(): void
    {
        $runtime = new Runtime();
        $class = $runtime->vmContext->classes['random\\randomizer'] ?? null;
        self::assertNotNull($class);
        self::assertArrayHasKey('__serialize', $class->methods);
        self::assertArrayHasKey('__unserialize', $class->methods);
    }

    public function testRandomizerSerializeRoundTrip(): void
    {
        $out = shell_exec('php bin/vm.php test/repro/maintainer-parity-probes/probe_randomizer_serialize.php 2>&1');
        self::assertIsString($out);
        self::assertStringContainsString('ok', $out);
    }

    public function testDecodeMagicSerializePropertyBag(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $engineObj = new \PHPCompiler\VM\ObjectEntry($ctx->classes['random\\engine\\mt19937']);
        $engineObj->constructed = true;
        $mt = new \PHPCompiler\ext\random\Mt19937Instance();
        $mt->seed(42);
        \PHPCompiler\ext\random\RandomEngineStorage::attachMt19937($engineObj, $mt);
        $engine = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_OBJECT);
        $engine->object($engineObj);
        $randomizerObj = new \PHPCompiler\VM\ObjectEntry($ctx->classes['random\\randomizer']);
        $randomizerObj->constructed = true;
        \PHPCompiler\ext\random\RandomizerStorage::setEngine($randomizerObj, $engine);
        $randomizerVar = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_OBJECT);
        $randomizerVar->object($randomizerObj);
        $blob = VmSerialize::serializeValue($ctx, $randomizerVar);
        $decoded = VmSerialize::decodeMagicSerializePropertyBag($ctx, $blob);
        self::assertNotFalse($decoded);
        self::assertSame(\PHPCompiler\VM\Variable::TYPE_ARRAY, $decoded->type);
        $restored = VmSerialize::instantiateWithUnserializeData(
            $ctx,
            $ctx->classes['random\\randomizer'],
            $decoded
        );
        self::assertSame('random\\randomizer', strtolower($restored->toObject()->class->name));
    }
}
