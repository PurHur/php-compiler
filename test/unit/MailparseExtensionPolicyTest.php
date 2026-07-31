<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\mailparse\MailparseExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** MailparseExtensionPolicy host / ENABLE gate (#24908). */
final class MailparseExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostMailparse(): void
    {
        if (\extension_loaded('mailparse')) {
            self::markTestSkipped('host ext/mailparse loaded');
        }

        self::assertFalse(MailparseExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('mailparse')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'mailparse_msg_create')
        );
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('mailparse')) {
            self::markTestSkipped('host ext/mailparse loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_MAILPARSE');
        putenv('PHP_COMPILER_ENABLE_MAILPARSE=1');
        try {
            self::assertTrue(MailparseExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_MAILPARSE');
            } else {
                putenv('PHP_COMPILER_ENABLE_MAILPARSE='.$prevEnable);
            }
        }
    }
}
