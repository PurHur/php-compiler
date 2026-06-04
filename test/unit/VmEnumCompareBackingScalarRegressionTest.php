<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Enum case === / == with backing scalar must be false even when constants table stores scalars (#5832, #5798).
 */
final class VmEnumCompareBackingScalarRegressionTest extends TestCase
{
    public function testLegacyScalarEnumConstantFetchRejectsBackingScalarCompare(): void
    {
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int { case A = 1; }
PHP, 'enum_decl.php'));
        $enum = $runtime->vmContext->classes['e'];
        $scalar = new VM\Variable(VM\Variable::TYPE_INTEGER);
        $scalar->int(1);
        $enum->constants['a'] = $scalar;
        unset($enum->enumCaseCanonicalNames['a']);

        ob_start();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
var_export([E::A === 1, E::A == 1, 1 === E::A, 1 == E::A]);
PHP, 'enum_compare.php'));
        $output = ob_get_clean();

        $this->assertSame(
            "array (\n  0 => false,\n  1 => false,\n  2 => false,\n  3 => false,\n)",
            $output
        );
    }

    public function testUserEnumConstStillComparesAsScalar(): void
    {
        ob_start();
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int { case A = 1; public const FOO = 2; }
var_export([E::FOO === 2, E::FOO == 2]);
PHP, 'enum_user_const.php'));
        $output = ob_get_clean();

        $this->assertSame("array (\n  0 => true,\n  1 => true,\n)", $output);
    }
}
