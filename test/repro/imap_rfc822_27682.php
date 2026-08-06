<?php
/**
 * Issue #27682 — imap_rfc822_write_address / parse_adrlist / parse_headers.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/imap_rfc822_27682.php
 */
declare(strict_types=1);

foreach (['imap_rfc822_write_address', 'imap_rfc822_parse_adrlist', 'imap_rfc822_parse_headers'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', PHP_EOL;
}

$w = imap_rfc822_write_address('user', 'example.com', 'Name');
echo 'write=', $w, PHP_EOL;
echo 'named=', imap_rfc822_write_address(mailbox: 'a', hostname: 'b.com', personal: ''), PHP_EOL;

$list = imap_rfc822_parse_adrlist('Name <user@example.com>', 'localhost');
echo 'adr_n=', count($list), PHP_EOL;
echo 'adr_mbox=', $list[0]->mailbox ?? '-', PHP_EOL;
echo 'adr_host=', $list[0]->host ?? '-', PHP_EOL;
echo 'adr_pers=', $list[0]->personal ?? '-', PHP_EOL;

$bare = imap_rfc822_parse_adrlist('onlyuser', 'defaulthost.com');
echo 'bare_host=', $bare[0]->host ?? '-', PHP_EOL;

$headers = "From: Alice <alice@example.com>\r\nTo: bob@example.com\r\nSubject: Hello\r\n\r\n";
$h = imap_rfc822_parse_headers($headers);
echo 'subj=', $h->subject ?? '-', PHP_EOL;
echo 'fromaddr=', $h->fromaddress ?? '-', PHP_EOL;
echo 'from_n=', isset($h->from) ? count($h->from) : 0, PHP_EOL;
echo 'to_mbox=', $h->to[0]->mailbox ?? '-', PHP_EOL;

$rf = new ReflectionFunction('imap_rfc822_write_address');
echo 'w_params=';
foreach ($rf->getParameters() as $i => $p) {
    echo ($i ? ',' : ''), $p->getName();
}
echo PHP_EOL;
echo 'w_ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', PHP_EOL;
$rf2 = new ReflectionFunction('imap_rfc822_parse_headers');
echo 'h_default=', $rf2->getParameters()[1]->isDefaultValueAvailable()
    ? $rf2->getParameters()[1]->getDefaultValue()
    : '-', PHP_EOL;
