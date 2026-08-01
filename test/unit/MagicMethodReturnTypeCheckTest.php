<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issues #4988 #26432 #26463 */
final class MagicMethodReturnTypeCheckTest extends TestCase
{
    /**
     * @dataProvider invalidMagicMethodProvider
     */
    public function testInvalidMagicMethodReturnTypeFailsAtCompileTime(string $code, string $message): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage($message);
        $runtime->parseAndCompile($code, 'invalid_magic.php');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function invalidMagicMethodProvider(): iterable
    {
        yield '__sleep int' => [
            <<<'PHP'
<?php
class C0 { public function __sleep(): int { return 0; } }
PHP,
            'C0::__sleep(): Return type must be array when declared',
        ];
        yield '__wakeup stdClass' => [
            <<<'PHP'
<?php
class C0b {
    public function __sleep(): array { return []; }
    public function __wakeup(): stdClass {}
}
PHP,
            'C0b::__wakeup(): Return type must be void when declared',
        ];
        yield '__serialize int' => [
            <<<'PHP'
<?php
class C1 { public function __serialize(): int { return 1; } }
PHP,
            'C1::__serialize(): Return type must be array when declared',
        ];
        yield '__unserialize int' => [
            <<<'PHP'
<?php
class C2 { public function __unserialize(array $d): int { return 1; } }
PHP,
            'C2::__unserialize(): Return type must be void when declared',
        ];
        yield '__clone int' => [
            <<<'PHP'
<?php
class C3 { public function __clone(): int { return 1; } }
PHP,
            'C3::__clone(): Return type must be void when declared',
        ];
        yield '__set int' => [
            <<<'PHP'
<?php
class Cs { public function __set(string $n, mixed $v): int { return 1; } }
PHP,
            'Cs::__set(): Return type must be void when declared',
        ];
        yield '__unset int' => [
            <<<'PHP'
<?php
class Cu { public function __unset(string $n): int { return 1; } }
PHP,
            'Cu::__unset(): Return type must be void when declared',
        ];
        yield '__isset int' => [
            <<<'PHP'
<?php
class Ci { public function __isset(string $n): int { return 1; } }
PHP,
            'Ci::__isset(): Return type must be bool when declared',
        ];
        yield '__isset string' => [
            <<<'PHP'
<?php
class Cis { public function __isset(string $n): string { return 'x'; } }
PHP,
            'Cis::__isset(): Return type must be bool when declared',
        ];
        yield '__isset nullable bool' => [
            <<<'PHP'
<?php
class Cin { public function __isset(string $n): ?bool { return true; } }
PHP,
            'Cin::__isset(): Return type must be bool when declared',
        ];
        yield '__construct return type' => [
            <<<'PHP'
<?php
class C4 { public function __construct(): int { return 1; } }
PHP,
            'Method C4::__construct() cannot declare a return type',
        ];
        yield '__destruct return type' => [
            <<<'PHP'
<?php
class C4d { public function __destruct(): void {} }
PHP,
            'Method C4d::__destruct() cannot declare a return type',
        ];
        yield '__debugInfo string' => [
            <<<'PHP'
<?php
class C5 { public function __debugInfo(): string { return 'x'; } }
PHP,
            'C5::__debugInfo(): Return type must be ?array when declared',
        ];
        yield '__serialize nullable array' => [
            <<<'PHP'
<?php
class C6 { public function __serialize(): ?array { return []; } }
PHP,
            'C6::__serialize(): Return type must be array when declared',
        ];
        yield '__toString int' => [
            <<<'PHP'
<?php
class T1 { public function __toString(): int { return 1; } }
PHP,
            'T1::__toString(): Return type must be string when declared',
        ];
        yield '__toString void' => [
            <<<'PHP'
<?php
class T2 { public function __toString(): void {} }
PHP,
            'T2::__toString(): Return type must be string when declared',
        ];
        yield '__toString nullable string' => [
            <<<'PHP'
<?php
class T3 { public function __toString(): ?string { return 'x'; } }
PHP,
            'T3::__toString(): Return type must be string when declared',
        ];
        yield '__toString protected' => [
            <<<'PHP'
<?php
class T4 { protected function __toString() { return 'x'; } }
PHP,
            'Access level to T4::__toString() must be public (as in class Stringable)',
        ];
        yield '__toString private' => [
            <<<'PHP'
<?php
class T5 { private function __toString() { return 'x'; } }
PHP,
            'Access level to T5::__toString() must be public (as in class Stringable)',
        ];
        yield '__toString static' => [
            <<<'PHP'
<?php
class T6 { public static function __toString(): string { return 'x'; } }
PHP,
            'Method T6::__toString() cannot be static',
        ];
    }

    public function testValidMagicMethodReturnTypesCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Good {
    public function __construct() {}
    public function __sleep(): array { return []; }
    public function __wakeup(): void {}
    public function __serialize(): array { return []; }
    public function __unserialize(array $d): void {}
    public function __clone(): void {}
    public function __set(string $n, mixed $v): void {}
    public function __unset(string $n): void {}
    public function __isset(string $n): bool { return false; }
    public function __debugInfo(): ?array { return null; }
    public function __destruct() {}
    public function __toString(): string { return 'Good'; }
}
echo (string) new Good(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid_magic.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("Good\n", ob_get_clean());
    }

    public function testNeverMagicMethodReturnTypesCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Good {
    public function __wakeup(): never { throw new Exception('no wakeup'); }
    public function __unserialize(array $d): never { throw new Exception('no unserialize'); }
    public function __clone(): never { throw new Exception('no clone'); }
    public function __set(string $n, mixed $v): never { throw new Exception('no set'); }
    public function __unset(string $n): never { throw new Exception('no unset'); }
    public function __isset(string $n): never { throw new Exception('no isset'); }
}
echo Good::class, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid_magic_never.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("Good\n", ob_get_clean());
    }

    public function testIssetTrueFalseReturnTypesCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class T { public function __isset(string $n): true { return true; } }
class F { public function __isset(string $n): false { return false; } }
echo T::class, F::class, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid_magic_isset_true_false.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("TF\n", ob_get_clean());
    }

    /**
     * @dataProvider magicMethodWithArgsProvider
     * @covers issues #25023 #25029
     */
    public function testMagicMethodWithArgsFailsAtCompileTime(string $code, string $message): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage($message);
        $runtime->parseAndCompile($code, 'magic_with_args.php');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function magicMethodWithArgsProvider(): iterable
    {
        yield '__wakeup optional' => [
            <<<'PHP'
<?php
class W { function __wakeup($a = null) {} }
PHP,
            'Method W::__wakeup() cannot take arguments',
        ];
        yield '__destruct optional' => [
            <<<'PHP'
<?php
class D { function __destruct($a = null) {} }
PHP,
            'Method D::__destruct() cannot take arguments',
        ];
        yield '__clone optional' => [
            <<<'PHP'
<?php
class Cl { function __clone($a = null) {} }
PHP,
            'Method Cl::__clone() cannot take arguments',
        ];
        yield '__serialize required' => [
            <<<'PHP'
<?php
class S { function __serialize($a) {} }
PHP,
            'Method S::__serialize() cannot take arguments',
        ];
        yield '__debugInfo optional' => [
            <<<'PHP'
<?php
class Di { function __debugInfo($a = null) {} }
PHP,
            'Method Di::__debugInfo() cannot take arguments',
        ];
        yield '__toString required' => [
            <<<'PHP'
<?php
class A { public function __toString($x) { return "x"; } }
PHP,
            'Method A::__toString() cannot take arguments',
        ];
    }
}
