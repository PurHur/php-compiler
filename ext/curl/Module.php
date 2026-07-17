<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * curl extension module entry (php-src ext/curl/interface.c; issue #6999, #16659, #19671).
 *
 * libcurl HTTP client parity tracked in #3325; curl_multi in #3721.
 * Phase 2 keeps introspection helpers in-tree via {@see VmCurlCore}; CURLFile /
 * CURLStringFile / curl_file_create / curl_share_* stay withheld until
 * {@see CurlExtensionPolicy::advertisesExtension()} (#19728).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!CurlExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['curl'];
    }

    public function getExtensionVersion(): string
    {
        return VmCurlCore::LIBCURL_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        foreach (CurlConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!CurlExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        $functions = [
            new curl_escape(),
            new curl_unescape(),
            new curl_version(),
            new curl_strerror(),
            new curl_multi_strerror(),
            new curl_upkeep(),
        ];
        if (CurlExtensionPolicy::advertisesFileClasses()) {
            $functions[] = new curl_file_create();
        }
        if (CurlExtensionPolicy::advertisesShareHandles()) {
            $functions[] = new curl_share_init();
            $functions[] = new curl_share_setopt();
            $functions[] = new curl_share_close();
        }
        if (CurlExtensionPolicy::advertisesEasyHandleStubs()) {
            $functions[] = new curl_init();
            $functions[] = new curl_setopt();
            $functions[] = new curl_setopt_array();
            $functions[] = new curl_close();
        }

        return $functions;
    }
}
