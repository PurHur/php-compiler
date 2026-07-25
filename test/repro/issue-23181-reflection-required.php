<?php
foreach (['substr', 'json_encode', 'json_decode', 'explode', 'preg_match', 'hash', 'openssl_encrypt', 'array_slice'] as $f) {
    $rf = new ReflectionFunction($f);
    echo $f, ' req=', $rf->getNumberOfRequiredParameters(), ' num=', $rf->getNumberOfParameters();
    foreach ($rf->getParameters() as $p) {
        echo ' [', $p->getName(), ' opt=', $p->isOptional() ? 'Y' : 'N';
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        echo ']';
    }
    echo "\n";
}
