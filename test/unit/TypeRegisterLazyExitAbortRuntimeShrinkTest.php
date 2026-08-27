<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::register always-on LibcExtern::ensureExitAbort (#35428 / leftover #33267).
 *
 * Context::lookupFunction lazy-ensures exit/abort; ensureExitAbort must not call
 * lookupFunction (re-entrancy). Peer: TypeRegisterLazyStringTriggerError (#35392).
 */
final class TypeRegisterLazyExitAbortRuntimeShrinkTest extends TestCase
{
    public function testTypeRegisterDropsEagerEnsureExitAbort(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#35428', $type);

        $regPos = strpos($type, 'public function register(): void');
        $this->assertNotFalse($regPos);
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($initPos);
        $regBody = substr($type, $regPos, $initPos - $regPos);

        $this->assertStringNotContainsString(
            'LibcExtern::ensureExitAbort($this->context)',
            $regBody,
            'Type::register must not eagerly LibcExtern::ensureExitAbort (#35428)'
        );
        $this->assertStringNotContainsString(
            "\\PHPCompiler\\JIT\\LibcExtern::ensureExitAbort(\$this->context)",
            $regBody,
            'Type::register must not eagerly fully-qualified ensureExitAbort (#35428)'
        );
    }

    public function testLookupFunctionLazyEnsuresExitAbort(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35428', $ctx);
        $lookupPos = strpos($ctx, 'public function lookupFunction(string $name)');
        $this->assertNotFalse($lookupPos);
        $lookupBody = substr($ctx, $lookupPos, 900);
        $this->assertStringContainsString(
            "LibcExtern::ensureExitAbort(\$this)",
            $lookupBody,
            'Context::lookupFunction must lazy-ensure exit/abort (#35428)'
        );
        $this->assertStringContainsString(
            "'exit' === \$name || 'abort' === \$name",
            $lookupBody
        );
        $this->assertStringContainsString('tryGetRegisteredFunction', $ctx);
    }

    public function testEnsureExitAbortDoesNotCallLookupFunction(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('#35428', $owner);
        $fnPos = strpos($owner, 'public static function ensureExitAbort');
        $this->assertNotFalse($fnPos);
        $brace = strpos($owner, '{', $fnPos);
        $this->assertNotFalse($brace);
        $depth = 0;
        $end = $brace;
        $len = strlen($owner);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $owner[$i];
            if ('{' === $ch) {
                $depth++;
            } elseif ('}' === $ch) {
                $depth--;
                if (0 === $depth) {
                    $end = $i;
                    break;
                }
            }
        }
        $body = substr($owner, $fnPos, $end - $fnPos + 1);
        $this->assertStringNotContainsString(
            'lookupFunction(',
            $body,
            'ensureExitAbort must not call lookupFunction (re-entrancy with #35428)'
        );
        $this->assertStringContainsString('tryGetRegisteredFunction', $body);
        $this->assertStringContainsString('getNamedFunction', $body);
    }
}
