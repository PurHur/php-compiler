<?php

declare(strict_types=1);

namespace PHPCompiler;

use PhpParser\Node\Stmt\GroupUse;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPCompiler\Ast\GroupUseStripper;
use PHPUnit\Framework\TestCase;

/**
 * @see GroupUseStripper — NameResolver pairing on the bootstrap parse path (#2443, #2634).
 */
final class GroupUseStripperTest extends TestCase
{
    public function testRemovesGroupUseAfterNameResolution(): void
    {
        $code = <<<'PHP'
<?php

namespace DummyGroupUseNs;

use DummyPkg\Imported\{Alpha, Bravo};

final class Fixture {

}

PHP;

        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $ast = $parser->parse($code);
        self::assertIsArray($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new GroupUseStripper());
        $out = $traverser->traverse($ast);

        $finder = new NodeFinder();
        $stillGroup = $finder->findInstanceOf($out, GroupUse::class);
        self::assertSame([], $stillGroup, 'Stmt\\GroupUse should be stripped once aliases exist');
    }
}
