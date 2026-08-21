<?php

declare(strict_types=1);

// #33486 leftover of #7268 — deprecated noop; null arg is accepted (no typed OpenSSLAsymmetricKey check).
// @ suppresses E_DEPRECATED so VM/AOT output matches Zend with error_reporting default.
var_export(@openssl_free_key(null));
echo "\n";
