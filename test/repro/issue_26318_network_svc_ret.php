<?php
/**
 * #26318 — getservbyname/getservbyport/getprotobyname/getprotobynumber
 * Reflection return must include |false (ext/standard/basic_functions.stub.php / network.c).
 */
foreach (['getservbyname', 'getservbyport', 'getprotobyname', 'getprotobynumber'] as $fn) {
    $rt = (new ReflectionFunction($fn))->getReturnType();
    echo $fn, ' ret=', null === $rt ? '(none)' : (string) $rt, "\n";
}
