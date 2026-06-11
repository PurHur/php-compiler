<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHttpBuildQuery;
use PHPUnit\Framework\TestCase;

/** VM-native http_build_query builder (issue #4781). */
final class VmHttpBuildQueryTest extends TestCase
{
    public function testBuildMatchesZendSubset(): void
    {
        $this->assertSame(
            'a=1&b=2',
            VmHttpBuildQuery::build(['a' => 1, 'b' => 2])
        );
        $this->assertSame(
            'foo=a+b&bar=x%26y',
            VmHttpBuildQuery::build(['foo' => 'a b', 'bar' => 'x&y'])
        );
        $this->assertSame(
            'nested%5Ba%5D=1&nested%5Bb%5D=2',
            VmHttpBuildQuery::build(['nested' => ['a' => 1, 'b' => 2]])
        );
        $this->assertSame(
            'a=1;b=2',
            VmHttpBuildQuery::build(['a' => 1, 'b' => 2], '', ';')
        );
        $this->assertSame(
            't=1&f=0',
            VmHttpBuildQuery::build(['t' => true, 'f' => false])
        );
        $this->assertSame(
            '123a=1&456b=2',
            VmHttpBuildQuery::build(['123a' => 1, '456b' => 2], 'n')
        );
        $this->assertSame(
            'my_1=foo&my_2=bar',
            VmHttpBuildQuery::build([1 => 'foo', 2 => 'bar'], 'my_')
        );
        $this->assertSame(
            'nested%5B0%5D=a&nested%5B1%5D=b',
            VmHttpBuildQuery::build(['nested' => [0 => 'a', 1 => 'b']], 'p_')
        );
        $this->assertSame(
            'n0=foo&bar=baz',
            VmHttpBuildQuery::build([0 => 'foo', 'bar' => 'baz'], 'n')
        );
        $this->assertSame(
            'a=b%20c',
            VmHttpBuildQuery::build(['a' => 'b c'], '', '&', VmHttpBuildQuery::ENCODING_RFC3986)
        );
        $this->assertSame(
            'a=b+c',
            VmHttpBuildQuery::build(['a' => 'b c'], '', '&', VmHttpBuildQuery::ENCODING_RFC1738)
        );
        $this->assertSame(
            'user%5Bname%5D=x&user%5Bflag%5D=1',
            VmHttpBuildQuery::build(['user' => ['name' => 'x', 'flag' => true]])
        );
        $this->assertSame(
            'n=1&b=0',
            VmHttpBuildQuery::build(['n' => 1, 'b' => false])
        );
    }

    public function testVmBuiltinDoesNotCallHostHttpBuildQuery(): void
    {
        $root = \dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/http_build_query.php');
        $this->assertDoesNotMatchRegularExpression(
            '/\\\\http_build_query\s*\(/',
            $src
        );
    }
}
