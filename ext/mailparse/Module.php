<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ModuleAbstract;

/**
 * mailparse extension module entry (PECL mailparse / mailparse.c; #6383).
 *
 * Phase 1: procedural MIME create/parse/get_part_data + rfc822 address parse.
 * PHP-in-PHP RFC822/MIME header parser — no runtime/*.c growth.
 */
class Module extends ModuleAbstract
{
    /** PECL mailparse PHP_MAILPARSE_VERSION-style */
    private const MAILPARSE_VERSION = '3.1.6';

    public function getExtensionVersion(): string
    {
        return self::MAILPARSE_VERSION;
    }

    public function getFunctions(): array
    {
        if (!MailparseExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new mailparse_msg_create(),
            new mailparse_msg_parse(),
            new mailparse_msg_get_part_data(),
            new mailparse_msg_free(),
            new mailparse_rfc822_parse_addresses(),
        ];
    }
}
