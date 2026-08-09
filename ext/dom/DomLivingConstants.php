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
     * Living Dom\ globals for get_defined_constants(true)['dom'] (#29485).
     * Gated like {@see register()} — Zend 8.4+ only.
     *
     * @return array<string, int>
     */
    public static function registeredConstants(): array
    {
        if (!CompilerVersion::supportsDomLivingStandardNamespace()) {
            return [];
        }

        return [
            'Dom\\HTML_NO_DEFAULT_NS' => self::HTML_NO_DEFAULT_NS,
        ];
    }

    /**
     * Register Dom\HTML_NO_DEFAULT_NS for userland when PROFILE≥8.4
     * (php-src ext/dom/php_dom.stub.php const HTML_NO_DEFAULT_NS; #26008).
     */
    public static function register(Context $ctx): void
    {
        foreach (self::registeredConstants() as $name => $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ctx->defineConstant($name, $var);
        }
    }
}
