<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * mbstring extension module entry (php-src ext/mbstring/mbstring.c; issue #5695).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        foreach ([
            'MB_CASE_UPPER' => MbstringConstants::MB_CASE_UPPER,
            'MB_CASE_LOWER' => MbstringConstants::MB_CASE_LOWER,
            'MB_CASE_TITLE' => MbstringConstants::MB_CASE_TITLE,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new mb_strlen(),
            new mb_convert_case(),
        ];
    }
}
