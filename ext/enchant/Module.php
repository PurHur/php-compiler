<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * enchant extension module entry (php-src ext/enchant/enchant.c; #6230 / #20613).
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
            new enchant_broker_get_error(),
            new enchant_broker_list_dicts(),
            new enchant_broker_request_dict(),
            new enchant_broker_request_pwl_dict(),
            new enchant_broker_free_dict(),
            new enchant_broker_dict_exists(),
            new enchant_broker_set_ordering(),
            new enchant_broker_describe(),
            new enchant_dict_quick_check(),
            new enchant_dict_check(),
            new enchant_dict_suggest(),
            new enchant_dict_add(),
            // php-src @alias enchant_dict_add + #[\Deprecated(since: '8.0')] (#22270)
            new enchant_dict_add('enchant_dict_add_to_personal'),
            new enchant_dict_remove(),
            new enchant_dict_add_to_session(),
            new enchant_dict_remove_from_session(),
            new enchant_dict_is_added(),
            // php-src @alias enchant_dict_is_added + #[\Deprecated(since: '8.0')] (#22251)
            new enchant_dict_is_added('enchant_dict_is_in_session'),
            new enchant_dict_store_replacement(),
            new enchant_dict_get_error(),
            new enchant_dict_describe(),
        ];
    }
}
