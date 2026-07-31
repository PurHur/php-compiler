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
        // CURLOPT_*/CURLE_* only when ext/curl is advertised (Zend without curl has none; #23953).
        if (!CurlExtensionPolicy::advertisesExtension()) {
            return;
        }
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
            // curl_share_errno / curl_share_strerror — share error surface (php-src share.c; #20531)
            $functions[] = new curl_share_errno();
            $functions[] = new curl_share_strerror();
            // curl_share_init_persistent — PHP 8.5+ (php-src share.c; #20530)
            if (CurlExtensionPolicy::advertisesSharePersistentHandles()) {
                $functions[] = new curl_share_init_persistent();
            }
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
            // curl_reset / curl_pause — easy-handle lifecycle (php-src interface.c; #20494)
            $functions[] = new curl_reset();
            $functions[] = new curl_pause();
            // curl_copy_handle — easy clone (php-src interface.c; #20495)
            $functions[] = new curl_copy_handle();
        }
        if (CurlExtensionPolicy::advertisesMultiHandles()) {
            $functions[] = new curl_multi_init();
            $functions[] = new curl_multi_add_handle();
            $functions[] = new curl_multi_exec();
            $functions[] = new curl_multi_select();
            $functions[] = new curl_multi_getcontent();
            $functions[] = new curl_multi_remove_handle();
            $functions[] = new curl_multi_close();
            // curl_multi_info_read / setopt / errno (php-src multi.c; #20495)
            $functions[] = new curl_multi_info_read();
            $functions[] = new curl_multi_setopt();
            $functions[] = new curl_multi_errno();
            // curl_multi_get_handles — PHP 8.5+ (php-src multi.c; #20520)
            if (CurlExtensionPolicy::advertisesMultiGetHandles()) {
                $functions[] = new curl_multi_get_handles();
            }
        }

        return $functions;
    }
}
