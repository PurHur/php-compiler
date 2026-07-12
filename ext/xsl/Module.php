<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\ext\standard\XslConstants;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * xsl extension module entry (php-src ext/xsl/php_xsl.c; issue #3665).
 *
 * v1: XSLTProcessor via host ext/xsl bridge; native libxslt FFI is follow-up.
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        if (XsltHostBridge::available()) {
            $version = \phpversion('xsl');
            if (is_string($version) && '' !== $version) {
                return $version;
            }
        }

        return XslConstants::registeredConstants()['LIBXSLT_DOTTED_VERSION'];
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        if (!XslExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach (XslConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            if (\is_int($value)) {
                $var->int($value);
            } else {
                $var->string((string) $value);
            }
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [];
    }
}
