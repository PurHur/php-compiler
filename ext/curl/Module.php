<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * curl extension module entry (php-src ext/curl/interface.c; issue #6999, #16659).
 *
 * libcurl HTTP client parity tracked in #3325; curl_multi in #3721.
 * Phase 2 registers introspection builtins + CURLStringFile via {@see VmCurlCore}.
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
        if (!VmCurlCore::available()) {
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
        foreach ([
            'CURLOPT_URL' => CurlConstants::CURLOPT_URL,
            'CURLOPT_RETURNTRANSFER' => CurlConstants::CURLOPT_RETURNTRANSFER,
            'CURLOPT_POST' => CurlConstants::CURLOPT_POST,
            'CURLOPT_HTTPHEADER' => CurlConstants::CURLOPT_HTTPHEADER,
            'CURLE_OK' => CurlConstants::CURLE_OK,
            'CURLM_OK' => CurlConstants::CURLM_OK,
        ] as $name => $value) {
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

        return [
            new curl_escape(),
            new curl_unescape(),
            new curl_version(),
            new curl_strerror(),
            new curl_multi_strerror(),
            new curl_upkeep(),
        ];
    }
}
