<?php

declare(strict_types=1);

echo 'DNS_CAA defined: '.(defined('DNS_CAA') ? 'yes='.DNS_CAA : 'no')."\n";
echo 'STREAM_CRYPTO_METHOD_TLS_CLIENT: '.(defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? (string) STREAM_CRYPTO_METHOD_TLS_CLIENT : 'undef')."\n";
echo 'STREAM_CRYPTO_METHOD_TLS_SERVER: '.(defined('STREAM_CRYPTO_METHOD_TLS_SERVER') ? (string) STREAM_CRYPTO_METHOD_TLS_SERVER : 'undef')."\n";
