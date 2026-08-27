<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover LibcExtern always-on syscall / __phpc_host_* LLVM decls (#35457).
 *
 * MCJIT echo uses McjitEmbedHostEcho function-pointer globals (#21124).
 * EMBED write trampoline ensureSyscall before getNamedFunction. Peer:
 * TypeRegisterLazyExitAbort (#35428).
 */
final class LibcExternMcjitHostAliasRuntimeShrinkTest extends TestCase
{
    public function testRegisterDropsAlwaysOnSyscallAndHostAliases(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('#35457', $source);

        $regPos = strpos($source, 'public static function register(Context $context): void');
        $this->assertNotFalse($regPos);
        $ensurePos = strpos($source, 'public static function ensureStrncmp');
        $this->assertNotFalse($ensurePos);
        $regBody = substr($source, $regPos, $ensurePos - $regPos);

        foreach (['syscall', '__phpc_host_php_write', '__phpc_host_snprintf'] as $sym) {
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $regBody,
                "register() must not always-on declare {$sym} (#35457)"
            );
        }
        $this->assertStringNotContainsString(
            'self::ensure(',
            $regBody,
            'register() must not ensure leftover always-on specs (#35457)'
        );
    }

    public function testEnsureSyscallDoesNotCallLookupFunction(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $fnPos = strpos($source, 'public static function ensureSyscall');
        $this->assertNotFalse($fnPos);
        $brace = strpos($source, '{', $fnPos);
        $this->assertNotFalse($brace);
        $depth = 0;
        $end = $brace;
        $len = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $source[$i];
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
        $body = substr($source, $fnPos, $end - $fnPos + 1);
        $this->assertStringNotContainsString(
            'lookupFunction(',
            $body,
            'ensureSyscall must not call lookupFunction (#35457)'
        );
        $this->assertStringContainsString('tryGetRegisteredFunction', $body);
        $this->assertStringContainsString("getNamedFunction('syscall')", $body);
        $this->assertStringContainsString('functionType($i64, true, $i64)', $body);
    }

    public function testWriteTrampolineEnsuresSyscallBeforeUse(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $fnPos = strpos($source, 'private static function implementWriteViaHostAlias');
        $this->assertNotFalse($fnPos);
        $brace = strpos($source, '{', $fnPos);
        $this->assertNotFalse($brace);
        $depth = 0;
        $end = $brace;
        $len = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $source[$i];
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
        $body = substr($source, $fnPos, $end - $fnPos + 1);
        $this->assertStringContainsString('self::ensureSyscall($context)', $body);
        $ensureAt = strpos($body, 'self::ensureSyscall($context)');
        $lookupAt = strpos($body, "getNamedFunction('syscall')");
        $this->assertNotFalse($ensureAt);
        $this->assertNotFalse($lookupAt);
        $this->assertLessThan(
            $lookupAt,
            $ensureAt,
            'ensureSyscall must run before getNamedFunction(syscall)'
        );
    }

    public function testMcjitEmbedEchoUsesHostGlobalsNotLibcExternHostDecls(): void
    {
        $echo = (string) file_get_contents(__DIR__.'/../../lib/JIT/McjitEmbedHostEcho.php');
        $this->assertStringContainsString('__phpc_embed_php_write_fn', $echo);
        $this->assertStringContainsString('__phpc_embed_snprintf_fn', $echo);
        $this->assertStringContainsString('#21124', $echo);
        $this->assertStringNotContainsString("lookupFunction('__phpc_host_php_write')", $echo);
        $this->assertStringNotContainsString("lookupFunction('__phpc_host_snprintf')", $echo);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EmbedObEchoBridge.php');
        $this->assertStringContainsString('#35457', $bridge);
        $this->assertStringContainsString('McjitEmbedHostEcho', $bridge);
        $this->assertStringNotContainsString("lookupFunction('__phpc_host_php_write')", $bridge);
        $this->assertStringNotContainsString("lookupFunction('__phpc_host_snprintf')", $bridge);
    }
}
