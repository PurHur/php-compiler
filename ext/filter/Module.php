<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * filter extension module entry (php-src ext/filter/filter.c; issues #5839, #6028).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinEnums::register($runtime->vmContext);
        foreach ([
            'INPUT_POST' => VmFilter::INPUT_POST,
            'INPUT_GET' => VmFilter::INPUT_GET,
            'INPUT_COOKIE' => VmFilter::INPUT_COOKIE,
            'INPUT_ENV' => VmFilter::INPUT_ENV,
            'INPUT_SERVER' => VmFilter::INPUT_SERVER,
            'INPUT_SESSION' => VmFilter::INPUT_SESSION,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new filter_var(),
            new filter_input(),
            new filter_list(),
            new filter_id(),
        ];
    }
}
