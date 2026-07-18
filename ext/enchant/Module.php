<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * enchant extension module entry (php-src ext/enchant/enchant.c; #6230).
 *
 * Requires libenchant-2 via FFI — see Docker/dev/ubuntu-22.04/Dockerfile.
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        return '8.2.0';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!EnchantExtensionPolicy::advertisesClasses()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!EnchantExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new enchant_broker_init(),
            new enchant_broker_free(),
            new enchant_broker_request_dict(),
            new enchant_broker_free_dict(),
            new enchant_broker_dict_exists(),
            new enchant_dict_check(),
            new enchant_dict_suggest(),
        ];
    }
}
