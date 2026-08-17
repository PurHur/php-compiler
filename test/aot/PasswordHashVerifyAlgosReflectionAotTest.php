<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: password_verify named args + password_algos (#28917).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class PasswordHashVerifyAlgosReflectionAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'password_hash_verify_algos_named.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('password Reflection AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
