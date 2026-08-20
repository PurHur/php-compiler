<?php
declare(strict_types=1);

// Fixed SPKAC payload from openssl_spki_new(RSA-512, "phpc-spki-challenge", SHA256).
$spkac = 'MIHHMHMwXDANBgkqhkiG9w0BAQEFAANLADBIAkEAnaK0cDu5h1WJ70McsRz3ibmm8qq6ud6CpL5uuBOJsA4XF0sLV0iI98tMH49iVQiOIPf8jht9urIuFYziMPwIAQIDAQABFhNwaHBjLXNwa2ktY2hhbGxlbmdlMA0GCSqGSIb3DQEBCwUAA0EAgRrNWyJbHnMPJuQDH248x6W3sGVblGGCY8FBPIwI+DKupAFdOO0YyJWmkOvN2iE9doxibhWjgR9WsE6d16Lj+A==';

echo openssl_spki_verify($spkac) ? 'verify-ok' : 'verify-bad';
echo '|';
var_export(@openssl_spki_verify('bad!!!'));
echo "\n";
