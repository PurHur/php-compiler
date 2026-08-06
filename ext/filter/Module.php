<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * filter extension module entry (php-src ext/filter/filter.c; issues #5839, #6028, #13046).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinEnums::register($runtime->vmContext);
        BuiltinClasses::register($runtime->vmContext);
        foreach (FilterConstants::registeredConstants() as $name => $value) {
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
            new filter_has_var(),
            new filter_input_array(),
            new filter_var_array(),
            new filter_list(),
            new filter_id(),
        ];
    }
}
