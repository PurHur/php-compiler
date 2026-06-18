<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ctype;

use PHPCompiler\ModuleAbstract;

/**
 * ctype extension module entry (php-src ext/ctype/ctype.c; issue #6837).
 *
 * Character-classification handlers (VmCtype + CtypeJitHelper; #7253, #9234).
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            new ctype_blank(),
            new ctype_alnum(),
            new ctype_alpha(),
            new ctype_cntrl(),
            new ctype_digit(),
            new ctype_graph(),
            new ctype_lower(),
            new ctype_print(),
            new ctype_punct(),
            new ctype_space(),
            new ctype_upper(),
            new ctype_xdigit(),
        ];
    }
}
