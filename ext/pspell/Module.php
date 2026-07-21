<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * pspell extension module entry (php-src ext/pspell/pspell.c; #6294).
 *
 * Requires libaspell via FFI — install `aspell` / `aspell-en` for dictionaries.
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        return '8.3.0';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!PspellExtensionPolicy::advertisesExtension()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
        foreach (PspellConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!PspellExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new pspell_new(),
            new pspell_check(),
            new pspell_suggest(),
        ];
    }
}
