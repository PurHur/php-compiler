<?php
/**
 * Issue #23358 — checkdnsrr / dns_check_record / dns_get_record Reflection + named args
 * (php-src ext/standard/basic_functions.stub.php).
 */
foreach (['checkdnsrr', 'dns_check_record', 'dns_get_record'] as $fn) {
    $n = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $s = $p->getName();
        if ($p->isPassedByReference()) {
            $s = '&'.$s;
        }
        if ($p->isOptional()) {
            $s .= '=';
        }
        if ($p->isDefaultValueAvailable()) {
            $s .= var_export($p->getDefaultValue(), true);
        }
        $n[] = $s;
    }
    echo $fn, ': ', implode(',', $n), "\n";
}

try {
    var_export(checkdnsrr(hostname: 'localhost', type: 'A'));
    echo "\n";
} catch (Throwable $e) {
    echo 'checkdnsrr named: ', $e->getMessage(), "\n";
}

$auth = $add = null;
try {
    $r = dns_get_record(
        hostname: 'localhost',
        type: DNS_A,
        authoritative_name_servers: $auth,
        additional_records: $add,
        raw: false
    );
    echo 'dns_named is_array=', var_export(is_array($r), true),
        ' auth_is_array=', var_export(is_array($auth), true),
        ' add_is_array=', var_export(is_array($add), true),
        "\n";
} catch (Throwable $e) {
    echo 'dns_get_record named: ', $e->getMessage(), "\n";
}
