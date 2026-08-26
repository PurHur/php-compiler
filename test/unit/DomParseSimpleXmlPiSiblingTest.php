<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\dom\DomParseSimpleXmlJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * loadXML sibling parser recognizes ProcessingInstructions (#34952).
 *
 * @covers \PHPCompiler\ext\dom\DomParseSimpleXmlJitHelper::parseSiblingNodesArgv
 * @covers \PHPCompiler\ext\dom\DomParseSimpleXmlJitHelper::firstChildNodeArgv
 */
final class DomParseSimpleXmlPiSiblingTest extends TestCase
{
    public function test_parse_sibling_nodes_emits_pi(): void
    {
        $nodes = DomParseSimpleXmlJitHelper::parseSiblingNodesArgv('<?pi data?>');
        self::assertCount(1, $nodes);
        self::assertSame('pi', $nodes[0]['kind']);
        self::assertSame('pi', $nodes[0]['data']);
        self::assertSame('data', $nodes[0]['content']);
    }

    public function test_parse_sibling_nodes_pi_between_text(): void
    {
        $nodes = DomParseSimpleXmlJitHelper::parseSiblingNodesArgv('a<?x y?>b');
        self::assertCount(3, $nodes);
        self::assertSame(['kind' => 'text', 'data' => 'a'], $nodes[0]);
        self::assertSame('pi', $nodes[1]['kind']);
        self::assertSame('x', $nodes[1]['data']);
        self::assertSame('y', $nodes[1]['content']);
        self::assertSame(['kind' => 'text', 'data' => 'b'], $nodes[2]);
    }

    public function test_first_child_node_argv_pi(): void
    {
        $node = DomParseSimpleXmlJitHelper::firstChildNodeArgv('<r><?pi data?></r>');
        self::assertNotNull($node);
        self::assertSame('pi', $node['kind']);
        self::assertSame('pi', $node['data']);
        self::assertSame('data', $node['content']);
    }

    public function test_direct_child_markup_chunks_keeps_pi(): void
    {
        $chunks = DomParseSimpleXmlJitHelper::directChildMarkupChunks('<?pi data?>');
        self::assertSame(['<?pi data?>'], $chunks);
    }
}
