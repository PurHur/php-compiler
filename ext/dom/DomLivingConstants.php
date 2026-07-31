<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/** Dom\ namespace constants (php-src ext/dom/php_dom.stub.php; #6506, #26008). */
final class DomLivingConstants
{
    /** Dom\HTML_NO_DEFAULT_NS — disable default XHTML namespace on HTML parse. */
    public const HTML_NO_DEFAULT_NS = 1 << 20;

    /**
     * Register Dom\HTML_NO_DEFAULT_NS for userland when PROFILE≥8.4
     * (php-src ext/dom/php_dom.stub.php const HTML_NO_DEFAULT_NS; #26008).
     */
    public static function register(Context $ctx): void
    {
        if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
            return;
        }
        $var = new Variable(Variable::TYPE_INTEGER);
        $var->int(self::HTML_NO_DEFAULT_NS);
        $ctx->defineConstant('Dom\\HTML_NO_DEFAULT_NS', $var);
    }
}
