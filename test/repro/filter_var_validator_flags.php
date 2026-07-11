<?php

declare(strict_types=1);

$emailUnicode = filter_var('tëst@example.com', FILTER_VALIDATE_EMAIL, ['flags' => FILTER_FLAG_EMAIL_UNICODE]);
$urlPathRequired = filter_var('http://example.com', FILTER_VALIDATE_URL, ['flags' => FILTER_FLAG_PATH_REQUIRED]);
$ipNoPriv = filter_var('10.0.0.1', FILTER_VALIDATE_IP, ['flags' => FILTER_FLAG_NO_PRIV_RANGE]);

echo 'email_unicode='.var_export($emailUnicode, true)."\n";
echo 'url_path_required='.var_export($urlPathRequired, true)."\n";
echo 'ip_no_priv='.var_export($ipNoPriv, true)."\n";
