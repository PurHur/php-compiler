<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * curl extension module entry (php-src ext/curl/interface.c; issue #6999, #16659, #19671, #3325, #3721).
 *
 * Easy + multi HTTP via libcurl FFI when {@see CurlExtensionPolicy::advertisesExtension()}.
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
            // curl_escape/unescape require CurlHandle (php-src curl.stub.php; #20493)
            $functions[] = new curl_escape();
            $functions[] = new curl_unescape();
            $functions[] = new curl_init();
            $functions[] = new curl_setopt();
            $functions[] = new curl_setopt_array();
            $functions[] = new curl_exec();
            $functions[] = new curl_getinfo();
            $functions[] = new curl_error();
            $functions[] = new curl_errno();
            $functions[] = new curl_close();
        }
        if (CurlExtensionPolicy::advertisesMultiHandles()) {
            $functions[] = new curl_multi_init();
            $functions[] = new curl_multi_add_handle();
            $functions[] = new curl_multi_exec();
            $functions[] = new curl_multi_select();
            $functions[] = new curl_multi_getcontent();
            $functions[] = new curl_multi_remove_handle();
            $functions[] = new curl_multi_close();
        }

        return $functions;
    }
}
