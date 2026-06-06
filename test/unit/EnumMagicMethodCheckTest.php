<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5055 #5886 #6867 */
final class EnumMagicMethodCheckTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function disallowedEnumMagicMethodsProvider(): iterable
    {
        yield '__construct' => ['__construct', 'Enum E cannot include magic method __construct'];
        yield '__destruct' => ['__destruct', 'Enum E cannot include magic method __destruct'];
        yield '__clone' => ['__clone', 'Enum E cannot include magic method __clone'];
        yield '__sleep' => ['__sleep', 'Enum E cannot include magic method __sleep'];
        yield '__wakeup' => ['__wakeup', 'Enum E cannot include magic method __wakeup'];
        yield '__serialize' => ['__serialize', 'Enum E cannot include magic method __serialize'];
        yield '__unserialize' => ['__unserialize', 'Enum E cannot include magic method __unserialize'];
        yield '__get' => ['__get', 'Enum E cannot include magic method __get'];
        yield '__set_state' => ['__set_state', 'Enum E cannot include magic method __set_state'];
    }

    /**
     * @dataProvider disallowedEnumMagicMethodsProvider
     */
    public function testDisallowedEnumMagicMethodFailsAtCompileTime(string $method, string $message): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage($message);
        $runtime->parseAndCompile(<<<PHP
<?php
enum E {
    case A;
    public function {$method}() {}
}
PHP,
            'enum_'.$method.'.php'
        );
    }

    public function testEnumCallMagicMethodCompilesWhenSignatureValid(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    public function __call(string $name, array $args): mixed {
        return null;
    }
}
PHP,
            'enum_call.php'
        );
        $this->assertNotNull($block);
    }

    public function testEnumToStringMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum E cannot include magic method __toString');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E implements Stringable {
    case A;
    public function __toString(): string {
        return 'a';
    }
}
PHP,
            'enum_tostring.php'
        );
    }

    public function testEnumDebugInfoMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum E cannot include magic method __debugInfo');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int {
    case A = 1;
    public function __debugInfo(): array {
        return ['x' => 1];
    }
}
PHP,
            'enum_debuginfo.php'
        );
    }

    public function testBackedEnumStringableWithoutCustomToStringCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string implements Stringable {
    case A = 'x';
}
echo E::A;
PHP,
            'enum_stringable.php'
        );
        $this->assertNotNull($block);
    }
}
