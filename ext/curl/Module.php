<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * curl extension module entry (php-src ext/curl/interface.c; issue #6999).
 *
 * libcurl HTTP client parity tracked in #3325; curl_multi in #3721.
 * Register under {@see standard} so extension_loaded('curl') stays false until #3325 (#11627).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
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
            'CURLINFO_HTTP_CODE' => CurlConstants::CURLINFO_HTTP_CODE,
            'CURLINFO_EFFECTIVE_URL' => CurlConstants::CURLINFO_EFFECTIVE_URL,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new curl_init(),
            new curl_setopt(),
            new curl_exec(),
            new curl_close(),
            new curl_version(),
            new curl_escape(),
            new curl_unescape(),
        ];
    }
}
