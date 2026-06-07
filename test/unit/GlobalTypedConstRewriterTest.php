<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\GlobalTypedConstRewriter;
use PHPUnit\Framework\TestCase;

final class GlobalTypedConstRewriterTest extends TestCase
{
    public function testRewritesFileScopeTypedConst(): void
    {
        $src = <<<'PHP'
<?php
const string GLOBAL_NAME = 'alpha';
PHP;
        $out = GlobalTypedConstRewriter::rewrite($src);
        self::assertStringContainsString('/*phpc-global-typed-const:string*/ const GLOBAL_NAME', preg_replace('/\s+/', ' ', $out));
    }

    public function testDoesNotRewriteClassTypedConst(): void
    {
        $src = <<<'PHP'
<?php
class C { public const string X = 'a'; }
PHP;
        $out = GlobalTypedConstRewriter::rewrite($src);
        self::assertStringNotContainsString('phpc-global-typed-const', $out);
        self::assertStringContainsString('const string X', $out);
    }

    public function testRewritesNamespaceBlockTypedConst(): void
    {
        $src = <<<'PHP'
<?php
namespace N {
    const string NS_NAME = 'beta';
}
PHP;
        $out = GlobalTypedConstRewriter::rewrite($src);
        self::assertStringContainsString('/*phpc-global-typed-const:string*/ const NS_NAME', preg_replace('/\s+/', ' ', $out));
    }
}
