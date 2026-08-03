<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * fileinfo extension module entry (php-src ext/fileinfo/fileinfo.c; issue #3366).
 *
 * PHP-in-PHP MIME sniff via {@see VmFinfo} / {@see \PHPCompiler\ext\standard\VmMime}.
 * JIT/AOT: {@see JitFinfoFile} / {@see \PHPCompiler\ext\standard\JitMimeContentType} (#27196).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'fileinfo';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        foreach (FileinfoConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new finfo_open(),
            new finfo_file(),
            new finfo_buffer(),
            new finfo_close(),
            new finfo_set_flags(),
        ];
    }
}
