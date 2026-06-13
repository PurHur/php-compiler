<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * intl extension module entry (php-src ext/intl/php_intl.c; issue #5774).
 */
class Module extends ModuleAbstract
{
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
        return [
            new grapheme_strlen(),
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
