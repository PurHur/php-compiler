<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class VmEnumFromTest extends TestCase
{
    public function testBackedEnumFromAndTryFrom(): void
    {
        $code = <<<'PHP'
<?php
enum Color: string { case Red = 'red'; case Blue = 'blue'; }
echo Color::from('red')->name;
echo Color::tryFrom('red')->name;
echo Color::tryFrom('nope') === null ? 'null' : 'bad';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('RedRednull', $output);
    }

    public function testBackedEnumTryFromInTernaryWithSecondCall(): void
    {
        $code = <<<'PHP'
<?php
enum Color: string { case Red = 'red'; case Blue = 'blue'; }
echo Color::tryFrom('red') === null ? 'NULL' : Color::tryFrom('red')->name;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_try_from_ternary.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('Red', $output);
    }

    public function testBackedEnumFromInvalidUncaught(): void
    {
        $code = <<<'PHP'
<?php
enum Color: string { case Red = 'red'; }
Color::from('missing');
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from_invalid.php');
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('"missing" is not a valid backing value for enum Color');
        $runtime->run($block);
    }

    public function testBackedEnumFromInvalidCaughtAsValueError(): void
    {
        $code = <<<'PHP'
<?php
enum Color: string { case Red = 'red'; }
try {
    Color::from('missing');
    echo 'no throw';
} catch (ValueError $e) {
    echo $e->getMessage();
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from_caught.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('"missing" is not a valid backing value for enum Color', $output);
    }

    public function testIntBackedEnumFromAcceptsNumericString(): void
    {
        $code = <<<'PHP'
<?php
enum Level: int { case Low = 1; case High = 9; }
echo Level::from('9')->name;
echo Level::from(1)->name;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from_int_string.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('HighLow', $output);
    }

    public function testIntBackedEnumFromReturnsObjectType(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
echo gettype(E::from(1));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from_object_type.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('object', $output);
    }

    public function testBackedEnumFromSpaceshipMatchesCaseSingleton(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
echo E::A <=> E::from(1);
echo E::from(1) <=> E::A;
echo E::A <=> E::tryFrom(1);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from_spaceship.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('000', $output);
    }

    public function testIntBackedEnumFromRejectsNonNumericString(): void
    {
        $code = <<<'PHP'
<?php
enum Level: int { case Low = 1; }
Level::from('1abc');
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from_int_bad_string.php');
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Level::from(): Argument #1 ($value) must be of type int, string given');
        $runtime->run($block);
    }

    /** Issue #9603 — exact maintainer repro: valid backing values resolve to enum cases. */
    public function testIssue9603EnumFromTryFromRepro(): void
    {
        $code = <<<'PHP'
<?php
enum E: string {
    case A = 'a';
}

var_export(E::tryFrom('b') === null);
echo "\n";
var_export(E::tryFrom('a')->name);
echo "\n";
try {
    E::from('a');
    echo "from ok\n";
} catch (ValueError $e) {
    echo 'from fail: ', $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_9603_enum_from.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("true\n'A'\nfrom ok\n", $output);
    }
}
