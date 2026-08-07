<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * #28525 — mb_convert_encoding UTF-16BE/LE encodes scalars (not U+003F substitute).
 *
 * php-src: ext/mbstring/mbstring.c / libmbfl utf16be + utf16le filters.
 */
final class Issue28525MbConvertEncodingUtf16Test extends TestCase
{
    public function testVmUtf16EncodeMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_28525_mb_convert_encoding_utf16.php');
        $this->assertNotFalse($code);
        $block = $runtime->parseAndCompile($code, 'issue_28525.php');
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame(
            "BE=0041\nLE=4100\nJP_BE=3042\nJP_LE=4230\nEMOJI_BE=d83dde00\nISO=636166e9\n",
            $out
        );
    }
}
