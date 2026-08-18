<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * html_entity_decode() after htmlspecialchars_decode() must return the named entity (#32064).
 *
 * php-src: ext/standard/html.c — PHP_FUNCTION(html_entity_decode) / htmlspecialchars_decode
 */
final class HtmlEntityDecodeAfterHsDecode32064Test extends TestCase
{
    public function testEntityDecodeAfterHsDecodeMatchesZend(): void
    {
        $out = $this->runSnippet(<<<'PHP'
<?php
error_reporting(E_ALL);
var_dump(bin2hex(htmlspecialchars_decode('&quot;&amp;&lt;&#039;', ENT_QUOTES)));
var_dump(bin2hex(html_entity_decode('&eacute;', ENT_QUOTES, 'UTF-8')));
PHP
        );
        $this->assertSame("string(8) \"22263c27\"\nstring(4) \"c3a9\"\n", $out);
    }

    public function testReverseOrderStillMatchesZend(): void
    {
        $out = $this->runSnippet(<<<'PHP'
<?php
error_reporting(E_ALL);
var_dump(bin2hex(html_entity_decode('&eacute;', ENT_QUOTES, 'UTF-8')));
var_dump(bin2hex(htmlspecialchars_decode('&quot;&amp;&lt;&#039;', ENT_QUOTES)));
PHP
        );
        $this->assertSame("string(4) \"c3a9\"\nstring(8) \"22263c27\"\n", $out);
    }

    private function runSnippet(string $code): string
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32064.php'));
        return (string) ob_get_clean();
    }
}
