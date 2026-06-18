<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregReplacementExpand;
use PHPUnit\Framework\TestCase;

/** preg_replace() replacement expansion (#9599). */
final class PregReplacementExpandTest extends TestCase
{
    public function testExpandNumericBackreferences(): void
    {
        $ovector = [1, 3, 1, 3];
        $this->assertSame(
            '[12]',
            PregReplacementExpand::expand('[$1]', $ovector, 2, 'x12y')
        );
        $this->assertSame(
            '9x',
            PregReplacementExpand::expand('${1}x', [1, 2], 2, 'a9b')
        );
        $this->assertSame(
            'ba',
            PregReplacementExpand::expand('$2$1', [0, 2, 0, 1, 1, 2], 3, 'ab')
        );
    }
}
