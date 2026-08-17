<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: bare self/parent typed properties accept valid assigns (#31835).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class TypedPropSelfParent31835JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'typed_prop_self_parent_31835.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_prop_self_parent_31835.phpt',
            'typed_prop_self_parent_31835.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
