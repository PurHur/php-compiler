<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ZendSort;
use PHPUnit\Framework\TestCase;

final class ZendSortStableTieTest extends TestCase
{
    public function testSortPreservesInsertionOrderAmongComparatorTies(): void
    {
        $base = [];
        for ($i = 0; $i < 20; ++$i) {
            $base[] = ['id' => chr(97 + $i), 'v' => $i % 3];
        }
        ZendSort::sort(
            $base,
            static fn (array $a, array $b): int => $a['v'] <=> $b['v']
        );
        $order = implode(',', array_column($base, 'id'));
        $this->assertSame(
            'a,d,g,j,m,p,s,b,e,h,k,n,q,t,c,f,i,l,o,r',
            $order
        );
    }
}
