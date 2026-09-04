<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UriRawurlencodeReplaceJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * NestedJIT-safe Nyholm Uri rawurlencode replace (#36382).
 *
 * @group unit
 */
final class UriRawurlencodeReplaceJitHelper36382Test extends TestCase
{
    public function testGenSubDelimsWithUserInfoShape(): void
    {
        $gen = ':\/\?#\[\]@';
        $sub = '!\$&\'\(\)\*\+,;=';
        $pat = '/['.$gen.$sub.']++/';
        $this->assertSame(1, UriRawurlencodeReplaceJitHelper::patternSupported($pat));
        $this->assertSame('a b%3Ac', UriRawurlencodeReplaceJitHelper::replaceArgv($pat, 'a b:c'));
        $this->assertSame('u%40h', UriRawurlencodeReplaceJitHelper::replaceArgv($pat, 'u@h'));
    }

    public function testNyholmFilterPathShape(): void
    {
        $unreserved = 'a-zA-Z0-9_\-\.~';
        $sub = '!\$&\'\(\)\*\+,;=';
        $pat = '/(?:[^'.$unreserved.$sub.'%:@\/]++|%(?![A-Fa-f0-9]{2}))/';
        $this->assertSame(1, UriRawurlencodeReplaceJitHelper::patternSupported($pat));
        $this->assertSame('/a%20b', UriRawurlencodeReplaceJitHelper::replaceArgv($pat, '/a b'));
        $this->assertSame('%25', UriRawurlencodeReplaceJitHelper::replaceArgv($pat, '%'));
        $this->assertSame('%20', UriRawurlencodeReplaceJitHelper::replaceArgv($pat, '%20'));
    }

    public function testUnitReproMatchesHelper(): void
    {
        $this->assertSame(
            'a%20b%3Ac',
            UriRawurlencodeReplaceJitHelper::replaceArgv('/[ :]/', 'a b:c')
        );
    }
}
