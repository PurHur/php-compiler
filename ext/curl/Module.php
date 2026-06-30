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
 * {@see CurlExtensionPolicy} withholds Curl* handle CEs from class_exists() until then (#12117).
 * curl_escape/curl_unescape are withheld until #3325 so function_exists() agrees
 * with extension_loaded('curl') (#13588). Other CurlFunction stubs stay withheld (#11654).
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
        ];
    }
}
