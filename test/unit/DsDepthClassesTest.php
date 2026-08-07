<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\ds\DsExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** Ds depth class/factory registration (#28062). */
final class DsDepthClassesTest extends TestCase
{
    private ?string $prev = null;

    protected function setUp(): void
    {
        parent::setUp();
        $raw = getenv('PHP_COMPILER_ENABLE_DS');
        $this->prev = false === $raw ? null : $raw;
        putenv('PHP_COMPILER_ENABLE_DS=1');
        if (!DsExtensionPolicy::advertisesExtension()) {
            self::markTestSkipped('ds withheld');
        }
    }

    protected function tearDown(): void
    {
        if (null === $this->prev) {
            putenv('PHP_COMPILER_ENABLE_DS');
        } else {
            putenv('PHP_COMPILER_ENABLE_DS='.$this->prev);
        }
        parent::tearDown();
    }

    public function test_ds_depth_classes_and_factories_registered(): void
    {
        $runtime = new Runtime();
        foreach ([
            'ds\\pair', 'ds\\deque', 'ds\\stack', 'ds\\queue', 'ds\\heap', 'ds\\priorityqueue',
            'ds\\collection', 'ds\\hashable', 'ds\\sequence',
        ] as $lc) {
            self::assertArrayHasKey($lc, $runtime->vmContext->classes, $lc);
        }
        self::assertTrue($runtime->vmContext->classes['ds\\collection']->isInterface);
        self::assertTrue($runtime->vmContext->classes['ds\\hashable']->isInterface);
        self::assertTrue($runtime->vmContext->classes['ds\\sequence']->isInterface);
        self::assertArrayHasKey('count', $runtime->vmContext->classes['ds\\deque']->methods);
        self::assertArrayHasKey('push', $runtime->vmContext->classes['ds\\stack']->methods);
        self::assertArrayHasKey('pop', $runtime->vmContext->classes['ds\\heap']->methods);
        self::assertArrayHasKey('toarray', $runtime->vmContext->classes['ds\\pair']->methods);

        $mod = new \PHPCompiler\ext\ds\Module();
        $names = [];
        foreach ($mod->getFunctions() as $fn) {
            $names[] = \strtolower($fn->getName());
        }
        foreach (['ds\\seq', 'ds\\map', 'ds\\set', 'ds\\heap'] as $want) {
            self::assertContains($want, $names, $want);
        }
    }
}
