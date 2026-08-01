<?php

declare(strict_types=1);

/**
 * Issue #3663 — ext/imap phase-1 existence + local mbox + remote fail.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/issue_3663_imap_open.php
 */

foreach (['imap_open', 'imap_search', 'imap_fetchbody', 'imap_errors', 'imap_last_error', 'imap_num_msg', 'imap_headerinfo', 'imap_close'] as $fn) {
    echo $fn.'='.(function_exists($fn) ? 'yes' : 'no')."\n";
}
echo extension_loaded('imap') ? "ext=1\n" : "ext=0\n";
echo class_exists('IMAP\\Connection') ? "cls=1\n" : "cls=0\n";

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$mbox = imap_open($fixture, '', '');
echo $mbox instanceof IMAP\Connection ? "open=1\n" : "open=0\n";
echo 'num='.imap_num_msg($mbox)."\n";
$hits = imap_search($mbox, 'ALL');
echo 'search='.count($hits)."\n";
$h = imap_headerinfo($mbox, 1);
echo 'subj='.($h->subject ?? '')."\n";
$body = imap_fetchbody($mbox, 1, '1');
echo 'body='.trim($body)."\n";
imap_close($mbox);

$bad = @imap_open('{127.0.0.1:1/imap}INBOX', 'user', 'pass');
echo false === $bad ? "remote=false\n" : "remote=ok\n";
$err = imap_last_error();
echo is_string($err) && str_contains($err, "Couldn't open stream") ? "err=1\n" : "err=0\n";
$errs = imap_errors();
echo is_array($errs) && count($errs) > 0 ? "errs=1\n" : "errs=0\n";
