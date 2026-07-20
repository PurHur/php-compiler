<?php

/**
 * Repro #21433 — mail.add_x_header emits X-PHP-Originating-Script.
 *
 *   php bin/vm.php -d sendmail_path=…/mock_sendmail.sh -d mail.add_x_header=1 \
 *     test/repro/issue_21433_mail_add_x_header.php
 */
$mock = ini_get('sendmail_path');
$out = dirname($mock).'/mock_sendmail.last';
@unlink($out);
if ('1' !== ini_get('mail.add_x_header') && 'On' !== ini_get('mail.add_x_header')) {
    fwrite(STDERR, 'BAD_INI '.var_export(ini_get('mail.add_x_header'), true)."\n");
    exit(1);
}
mail('a@b.c', 's', 'body', 'From: x@y');
$raw = is_file($out) ? file_get_contents($out) : '';
if (!str_contains($raw, 'X-PHP-Originating-Script:')) {
    fwrite(STDERR, "MISSING_HEADER\n");
    exit(1);
}
echo "OK\n";
@unlink($out);
