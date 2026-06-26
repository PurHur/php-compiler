<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * intl extension module entry (php-src ext/intl/php_intl.c; issue #5774).
 *
 * Grapheme builtins are partial PHP implementations without ICU. Register under
 * {@see standard} so extension_loaded('intl') stays false until full ext/intl (#11472).
 * {@see IntlExtensionPolicy} withholds grapheme/intl_* from function_exists() until then (#11768).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        foreach ([
            'GRAPHEME_EXTR_COUNT' => VmGrapheme::EXTR_COUNT,
            'GRAPHEME_EXTR_MAXBYTES' => VmGrapheme::EXTR_MAXBYTES,
            'GRAPHEME_EXTR_MAXCHARS' => VmGrapheme::EXTR_MAXCHARS,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new grapheme_strlen(),
            new grapheme_substr(),
            new grapheme_strpos(),
            new grapheme_str_contains(),
            new grapheme_strstr(),
            new grapheme_stristr(),
            new grapheme_stripos(),
            new grapheme_strrpos(),
            new grapheme_extract(),
            new grapheme_levenshtein(),
            new grapheme_str_split(),
            new intl_get_error_code(),
        ];
    }
}
