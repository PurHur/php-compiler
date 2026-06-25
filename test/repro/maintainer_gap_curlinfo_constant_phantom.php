<?php

declare(strict_types=1);

echo 'curlinfo_defined=', defined('CURLINFO_HTTP_CODE') ? 'yes' : 'no', "\n";
echo 'curl_init_exists=', function_exists('curl_init') ? 'yes' : 'no', "\n";
echo 'extension_loaded_curl=', extension_loaded('curl') ? 'yes' : 'no', "\n";
