<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * VmPregEngine pattern backreferences `\1`… (#36380 Parsedown code_span).
 *
 * php-src: ext/pcre/php_pcre.c — PCRE2 numeric backrefs (pcre2pattern).
 */
final class PregPatternBackref36380Test extends TestCase
{
    public function testNumericBackrefMatchesZend(): void
    {
        $cases = [
            ['/(a)\1/', 'aa'],
            ['/(foo)\1/', 'foofoo'],
            ['/^([`]+)(.+?)\1$/', '`x`'],
            ['/^([`]++)[ ]*+(.+?)[ ]*+(?<![`])\1(?!`)/s', '`code span`'],
        ];
        foreach ($cases as [$re, $subj]) {
            $zendM = [];
            $zendR = \preg_match($re, $subj, $zendM);
            $this->assertSame(1, $zendR, "zend $re");

            // Host PHPUnit runs Zend; VM parity is via bin/vm.php repro + differential.
            // Assert the engine path used by bin/vm.php by requiring the pure matcher.
            $vmM = [];
            $vmR = \PHPCompiler\ext\standard\VmPreg::pregMatch($re, $subj, $vmM);
            $this->assertSame($zendR, $vmR, "vm r $re");
            $this->assertSame($zendM, $vmM, "vm matches $re");
        }
    }

    public function testOctalNulStillNotBackref(): void
    {
        $re = '/a\0b/';
        $subj = "a\0b";
        $zendM = [];
        $zendR = \preg_match($re, $subj, $zendM);
        $vmM = [];
        $vmR = \PHPCompiler\ext\standard\VmPreg::pregMatch($re, $subj, $vmM);
        $this->assertSame($zendR, $vmR);
        $this->assertSame($zendM, $vmM);
    }

    public function testNewlineEscapeIsLfNotLiteralN(): void
    {
        $re = '/a\nb/';
        $zendM = [];
        $this->assertSame(1, \preg_match($re, "a\nb", $zendM));
        $this->assertSame(0, \preg_match($re, 'anb'));

        $vmM = [];
        $this->assertSame(1, \PHPCompiler\ext\standard\VmPreg::pregMatch($re, "a\nb", $vmM));
        $this->assertSame($zendM, $vmM);
        $this->assertSame(0, \PHPCompiler\ext\standard\VmPreg::pregMatch($re, 'anb'));

        $replaced = \PHPCompiler\ext\standard\VmPreg::pregReplace('/[ ]*+\n/', ' ', "code span\nx");
        $this->assertSame(\preg_replace('/[ ]*+\n/', ' ', "code span\nx"), $replaced);
        $this->assertSame('code span x', $replaced);
    }
}
