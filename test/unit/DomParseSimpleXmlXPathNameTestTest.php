<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\dom\DomParseSimpleXmlJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * User-script AOT XPath name-test counting (#29139).
 *
 * @covers \PHPCompiler\ext\dom\DomParseSimpleXmlJitHelper::countXPathNameTestArgv
 */
final class DomParseSimpleXmlXPathNameTestTest extends TestCase
{
    public function test_unprefixed_skips_default_namespace_elements(): void
    {
        $xml = '<r xmlns="urn:def"><c/></r>';
        self::assertSame(0, DomParseSimpleXmlJitHelper::countXPathNameTestArgv($xml, 'c'));
        self::assertSame(1, DomParseSimpleXmlJitHelper::countXPathNameTestArgv($xml, 'd:c', ['d' => 'urn:def']));
        self::assertNull(DomParseSimpleXmlJitHelper::countXPathNameTestArgv($xml, 'd:c', []));
    }

    public function test_unprefixed_matches_null_namespace(): void
    {
        $xml = '<r><c/><c/></r>';
        self::assertSame(2, DomParseSimpleXmlJitHelper::countXPathNameTestArgv($xml, 'c'));
    }

    public function test_getelements_by_tag_still_counts_across_namespaces(): void
    {
        $xml = '<r xmlns="urn:def"><c/></r>';
        self::assertSame(1, DomParseSimpleXmlJitHelper::countTagArgv($xml, 'c'));
    }
}
