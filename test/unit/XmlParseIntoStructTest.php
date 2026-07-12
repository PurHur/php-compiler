<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\xml\XmlParserSupport;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\ext\xml\VmXmlStructBuilder;
use PHPUnit\Framework\TestCase;

/** xml_parse_into_struct() struct builder (#3494). */
final class XmlParseIntoStructTest extends TestCase
{
    public function testStructBuilderSingleSelfClosingElement(): void
    {
        $built = VmXmlStructBuilder::build('<b/>');
        $result = $built->result();
        self::assertSame(1, $result['values']->getNumElements());
        $entry = $result['values']->findIndex(0);
        self::assertNotNull($entry);
        $ht = $entry->resolveIndirect()->toArray();
        self::assertSame('B', $ht->find('tag')->resolveIndirect()->toString());
        self::assertSame('complete', $ht->find('type')->resolveIndirect()->toString());
    }

    public function testStructBuilderNestedElements(): void
    {
        $built = VmXmlStructBuilder::build('<a><b/></a>');
        $result = $built->result();
        self::assertSame(3, $result['values']->getNumElements());
        self::assertNotNull($result['index']->find('B'));
        self::assertNotNull($result['index']->find('A'));
    }

    public function testParseIntoStructDirectVmXml(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $parser = XmlParserSupport::createParser($ctx)->toObject()->id;
        $parsed = VmXml::parseIntoStruct($ctx, $parser, '<a><b/></a>');
        self::assertSame(1, $parsed['status']);
        self::assertSame(3, $parsed['values']->getNumElements());
        self::assertNotNull($parsed['index']->find('B'));
    }

    public function testParseIntoStructVmIntegration(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$vals = [];
$idx = [];
$status = xml_parse_into_struct(xml_parser_create(), '<a><b/></a>', $vals, $idx);
echo $status, "\n";
echo count($vals), "\n";
echo (int) array_key_exists('B', $idx), "\n";
echo $vals[1]['tag'], ':', $vals[1]['type'], "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'xml_struct.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n3\n1\nB:complete\n", ob_get_clean());
    }
}
