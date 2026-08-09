<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Variable;

/**
 * mailparse extension module entry (PECL mailparse / mailparse.c; #6383, #22230, #24908, #28064).
 *
 * Advertise mailparse_* / extension_loaded('mailparse') only when
 * {@see MailparseExtensionPolicy::advertisesExtension()}.
 * MIME create/parse + multipart structure/extract/parse_file + transfer helpers.
 * PHP-in-PHP RFC822/MIME parser — no runtime/*.c growth.
 */
class Module extends ModuleAbstract
{
    /** PECL mailparse PHP_MAILPARSE_VERSION-style */
    private const MAILPARSE_VERSION = '3.1.6';

    public function getExtensionVersion(): string
    {
        return self::MAILPARSE_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!MailparseExtensionPolicy::advertisesExtension()) {
            return;
        }
        require_once __DIR__.'/MailparseConstants.php';
        foreach (MailparseConstants::registeredConstants() as $name => $value) {
            $var = new Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!MailparseExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new mailparse_msg_create(),
            new mailparse_msg_parse(),
            new mailparse_msg_parse_file(),
            new mailparse_msg_get_part_data(),
            new mailparse_msg_get_part(),
            new mailparse_msg_get_structure(),
            new mailparse_msg_extract_part(),
            new mailparse_msg_extract_part_file(),
            new mailparse_msg_extract_whole_part_file(),
            new mailparse_msg_free(),
            new mailparse_rfc822_parse_addresses(),
            new mailparse_determine_best_xfer_encoding(),
            new mailparse_stream_encode(),
            new mailparse_uudecode_all(),
        ];
    }
}
