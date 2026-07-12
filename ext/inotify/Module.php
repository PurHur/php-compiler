<?php

declare(strict_types=1);

namespace PHPCompiler\ext\inotify;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * inotify extension module entry (php-src ext/inotify/inotify.c; issue #6410).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!InotifyExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach (InotifyConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!InotifyExtensionPolicy::advertisesExtension() || !VmInotify::available()) {
            return [];
        }

        return [
            new inotify_init(),
            new inotify_add_watch(),
            new inotify_rm_watch(),
            new inotify_read(),
        ];
    }
}
