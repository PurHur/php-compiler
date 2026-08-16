<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: sodium_crypto_aead_aes256gcm_is_available() Reflection return bool (#27775).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class SodiumAes256gcmIsAvailableReflection27775VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'sodium_aes256gcm_is_available_reflection.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/sodium_aes256gcm_is_available_reflection.phpt',
            'sodium_aes256gcm_is_available_reflection.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
