<?php
declare(strict_types=1);

// Fixed RSA-512 PEM; openssl_spki_new(PEM, challenge, SHA256) is deterministic under libcrypto.
$pem = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIBVQIBADANBgkqhkiG9w0BAQEFAASCAT8wggE7AgEAAkEAxQslZA3Hqt0+EvMI
il1kYiS0u68Tgvky8fCMz+k/knO5nQQGpG9cXBbdCcTBFfEYBgdMbz1lAcbIabZf
a9GOCwIDAQABAkEAtq8bzoS8Ht0ahQUQYQAvZpKzgeLTCzYxloA4fTa6uvKuWs1b
34kOzQLqgeIXydv+0TF6D8XBOTqxEAjouYi42QIhAP66Jd0WeabXXJNTs1aHzQRv
E8ZgjcbIc0sIAU9iHuM/AiEAxgc1R9b0S9RO79gIVXoGDwJS/GXB4vrMRxDdLHEM
/jUCIQDiI9RVkQxzKCLR0K8YFPvYAdzmcvWrEm34oKS5Gv0c9QIge8vByTld25HM
DzBUdWslInjnfBX5EXaMAdlPCxtZbgkCIAwkWs03daPtMbutBho7OxPaw9u5NxUt
WXgzWYqA8tcu
-----END PRIVATE KEY-----
PEM;

// Captured from Zend 8.2.32 / VM with the PEM above (same SPKAC both sides).
$expected = 'SPKAC=MIHBMG0wXDANBgkqhkiG9w0BAQEFAANLADBIAkEAxQslZA3Hqt0+EvMIil1kYiS0u68Tgvky8fCMz+k/knO5nQQGpG9cXBbdCcTBFfEYBgdMbz1lAcbIabZfa9GOCwIDAQABFg1waHBjLXNwa2ktbmV3MA0GCSqGSIb3DQEBCwUAA0EAFmgn5zjcI2Gs2ga1bcfnkOM92oMXmvJvZA8ie32McDjxoqeGK+Eow62J+msICqw6qdinVDdYXXPgp14xele4UQ==';

$spkac = openssl_spki_new($pem, 'phpc-spki-new', OPENSSL_ALGO_SHA256);
echo ($spkac === $expected) ? 'new-ok' : 'new-bad';
echo '|';
var_export(@openssl_spki_new('not-a-key', 'x', OPENSSL_ALGO_SHA256));
echo "\n";
