<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\htmlentities;
use PHPCompiler\ext\standard\html_entity_decode;

/** VM builtins for htmlentities() / html_entity_decode() (#2472). */
final class HtmlentitiesBuiltinTest extends TestCase
{
    public function testHtmlentitiesEncodesAmpersand(): void
    {
        $this->assertSame('&amp;', \PHPCompiler\ext\standard\VmString::htmlentities('&'));
    }

    public function testRoundTripWithEntQuotes(): void
    {
        $raw = '<b>"\'</b>';
        $encoded = \PHPCompiler\ext\standard\VmString::htmlentities($raw, ENT_QUOTES);
        $this->assertSame($raw, \PHPCompiler\ext\standard\VmString::html_entity_decode($encoded, ENT_QUOTES));
    }

    public function testBuiltinExecute(): void
    {
        $code = <<<'PHP'
<?php
echo htmlentities('&'), "\n";
echo html_entity_decode('&amp;'), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame("&amp;\n&\n", ob_get_clean());
    }
}
