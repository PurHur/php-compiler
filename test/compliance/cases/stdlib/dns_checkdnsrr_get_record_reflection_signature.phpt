--TEST--
checkdnsrr/dns_check_record/dns_get_record Reflection matches php-src stub (#23358, basic_functions.stub.php)
--FILE--
<?php
foreach (['checkdnsrr', 'dns_check_record', 'dns_get_record'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo "== $fn ==\n";
    foreach ($r->getParameters() as $p) {
        echo $p->getName(),
            ' byref=', (int) $p->isPassedByReference(),
            ' opt=', (int) $p->isOptional(),
            ' defAvail=', (int) $p->isDefaultValueAvailable();
        if ($p->isDefaultValueAvailable()) {
            echo ' ', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
echo 'named_check=', var_export(checkdnsrr(hostname: 'localhost', type: 'A'), true), "\n";
$auth = $add = null;
$r = dns_get_record(
    hostname: 'localhost',
    type: DNS_A,
    authoritative_name_servers: $auth,
    additional_records: $add,
    raw: false
);
echo 'named_dns=', var_export(is_array($r), true),
    ' auth=', var_export(is_array($auth), true),
    ' add=', var_export(is_array($add), true),
    "\n";
?>
--EXPECT--
== checkdnsrr ==
hostname byref=0 opt=0 defAvail=0
type byref=0 opt=1 defAvail=1 'MX'
== dns_check_record ==
hostname byref=0 opt=0 defAvail=0
type byref=0 opt=1 defAvail=1 'MX'
== dns_get_record ==
hostname byref=0 opt=0 defAvail=0
type byref=0 opt=1 defAvail=1 268435456
authoritative_name_servers byref=1 opt=1 defAvail=1 NULL
additional_records byref=1 opt=1 defAvail=1 NULL
raw byref=0 opt=1 defAvail=1 false
named_check=true
named_dns=true auth=true add=true
