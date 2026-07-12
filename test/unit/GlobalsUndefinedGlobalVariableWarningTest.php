<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\UndefinedGlobalVariableJitHelper;
use PHPUnit\Framework\TestCase;

final class GlobalsUndefinedGlobalVariableWarningTest extends TestCase
{
    public function testUndefinedGlobalVariableMessageMatchesZend(): void
    {
        self::assertSame(
            'Undefined global variable $w',
            ErrorReporter::undefinedGlobalVariableMessage('w')
        );
        self::assertSame(
            ErrorReporter::undefinedGlobalVariableMessage('w'),
            UndefinedGlobalVariableJitHelper::warningMessage('w')
        );
    }
}
