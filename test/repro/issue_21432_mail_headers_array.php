<?php

/**
 * Repro #21432 — mail() array additional_headers ValueError parity.
 *
 *   php bin/vm.php -d sendmail_path=…/mock_sendmail.sh test/repro/issue_21432_mail_headers_array.php
 */
try {
    mail('a@b.c', 'subj', 'body', ['To' => 'evil@x']);
    fwrite(STDERR, "NO_THROW\n");
    exit(1);
} catch (ValueError $e) {
    if (!str_contains($e->getMessage(), '"To"')) {
        fwrite(STDERR, "BAD_MSG: ".$e->getMessage()."\n");
        exit(1);
    }
}
echo "OK\n";
