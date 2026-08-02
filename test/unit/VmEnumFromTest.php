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

    /** Issue #20072 — null coerces to backing 0 / "0" under weak types (zend_enum.c). */
    public function testBackedEnumFromNullCoercesLikeZend(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; }
enum I: int { case A = 1; }
foreach ([
    fn() => E::from(null),
    fn() => I::from(null),
    fn() => I::tryFrom(null),
    fn() => E::tryFrom(null),
] as $fn) {
    try {
        var_export($fn());
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from_null_coerce.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "ValueError: \"0\" is not a valid backing value for enum E\n"
            ."ValueError: 0 is not a valid backing value for enum I\n"
            ."NULL\n"
            ."NULL\n",
            $output
        );
    }

    /** Issue #26786 — null soft-pass emits Zend E_DEPRECATED then coerce/ValueError. */
    public function testBackedEnumFromNullEmitsDeprecation(): void
    {
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(function (int $no, string $msg): bool {
    echo 'DEP:', $msg, "\n";
    return true;
});
enum E: string { case A = 'a'; }
enum I: int { case A = 1; }
echo 'tryFrom_str=', var_export(E::tryFrom(null), true), "\n";
echo 'tryFrom_int=', var_export(I::tryFrom(null), true), "\n";
try { E::from(null); } catch (Throwable $e) {
    echo 'from_str=', get_class($e), ':', $e->getMessage(), "\n";
}
try { I::from(null); } catch (Throwable $e) {
    echo 'from_int=', get_class($e), ':', $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from_null_deprecation.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "tryFrom_str=DEP:E::tryFrom(): Passing null to parameter #1 (\$value) of type string|int is deprecated\n"
            ."NULL\n"
            ."tryFrom_int=DEP:I::tryFrom(): Passing null to parameter #1 (\$value) of type string|int is deprecated\n"
            ."NULL\n"
            ."DEP:E::from(): Passing null to parameter #1 (\$value) of type string|int is deprecated\n"
            ."from_str=ValueError:\"0\" is not a valid backing value for enum E\n"
            ."DEP:I::from(): Passing null to parameter #1 (\$value) of type string|int is deprecated\n"
            ."from_int=ValueError:0 is not a valid backing value for enum I\n",
            $output
        );
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
