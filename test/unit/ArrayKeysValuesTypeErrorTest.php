<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\array_keys;
use PHPCompiler\ext\standard\array_values;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM TypeError when array_keys() / array_values() receive non-array (#4138). */
final class ArrayKeysValuesTypeErrorTest extends TestCase
{
    /**
     * @dataProvider builtinProvider
     */
    public function testNonArrayThrowsTypeError(Internal $fn, string $name): void
    {
        $runtime = new Runtime();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string('bad');
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            $name.'(): Argument #1 ($array) must be of type array'
        );
        $fn->execute($frame);
    }

    /** @return list<array{Internal, string}> */
    public static function builtinProvider(): array
    {
        return [
            [new array_keys(), 'array_keys'],
            [new array_values(), 'array_values'],
        ];
    }
}
