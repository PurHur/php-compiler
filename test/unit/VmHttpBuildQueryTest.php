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
