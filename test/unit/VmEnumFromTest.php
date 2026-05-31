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
echo Color::tryFrom('nope') === null ? 'null' : 'bad';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_from.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('Rednull', $output);
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
}
