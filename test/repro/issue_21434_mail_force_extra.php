<?php
/**
 * Repro #21434 — mail.force_extra_parameters + additional_params escape.
 *
 *   php bin/vm.php -d sendmail_path=test/compliance/cases/stdlib/mail_fixtures/mock_sendmail_argv.sh \
 *     -d mail.force_extra_parameters='-f forced@example.com' \
 *     test/repro/issue_21434_mail_force_extra.php
 */
$mock = ini_get('sendmail_path');
$argvFile = dirname($mock) . '/mock_sendmail_argv.last';
@unlink($argvFile);

mail('a@b.c', 's', "b\n", '', '-f caller@example.com');
echo is_file($argvFile) ? trim((string) file_get_contents($argvFile)) : 'NO_CAPTURE';
echo "\n";
