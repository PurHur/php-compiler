<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\password_hash;
use PHPCompiler\ext\standard\password_needs_rehash;
use PHPCompiler\ext\standard\password_verify;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Direct VM builtin execute() enum case guards (#6242, #5904). */
final class PasswordEnumCaseTypeErrorTest extends TestCase
{
    public function testPasswordVerifyRejectsEnumCaseOperand(): void
    {
        $this->expectPasswordStringArgTypeError(
            new password_verify(),
            0,
            'password',
            'password_verify(): Argument #1 ($password) must be of type string, E given'
        );
    }

    public function testPasswordHashRejectsEnumCaseOperand(): void
    {
        $this->expectPasswordStringArgTypeError(
            new password_hash(),
            0,
            'password',
            'password_hash(): Argument #1 ($password) must be of type string, E given'
        );
    }

    public function testPasswordNeedsRehashRejectsEnumCaseOperand(): void
    {
        $this->expectPasswordStringArgTypeError(
            new password_needs_rehash(),
            0,
            'hash',
            'password_needs_rehash(): Argument #1 ($hash) must be of type string, E given'
        );
    }

    private function expectPasswordStringArgTypeError(
        object $builtin,
        int $missingAlgoArgIndex,
        string $unused,
        string $expectedMessage
    ): void {
        unset($unused);
        $runtime = new Runtime();
        $enumCase = self::enumCaseVariable('E', 'A', 'secret');
        $args = [$enumCase];
        if ($builtin instanceof password_hash || $builtin instanceof password_needs_rehash) {
            $algo = new VMVariable();
            $algo->int(1);
            $args[] = $algo;
        } elseif ($builtin instanceof password_verify) {
            $hash = new VMVariable();
            $hash->string('x');
            $args[] = $hash;
        } else {
            self::fail('unexpected builtin');
        }

        $frame = $builtin->getFrame($runtime->vmContext);
        $frame->calledArgs = $args;
        $frame->returnVar = new VMVariable();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage($expectedMessage);
        $builtin->execute($frame);
    }

    private static function enumCaseVariable(string $enumName, string $caseName, string $backing): VMVariable
    {
        $enum = new ClassEntry($enumName);
        $enum->isEnum = true;
        $enum->backedType = 'string';
        $backingVar = new VMVariable();
        $backingVar->string($backing);
        $var = new VMVariable(VMVariable::TYPE_ENUM_CASE);
        $var->enumCase(new EnumCaseEntry($enum, $caseName, $backingVar));

        return $var;
    }
}
