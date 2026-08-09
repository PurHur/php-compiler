--TEST--
stdlib GNUPG_* + MAILPARSE_EXTRACT_* constants when advertised (#28064)
--ENV--
PHP_COMPILER_ENABLE_GNUPG=1
PHP_COMPILER_ENABLE_MAILPARSE=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\gnupg\GnupgExtensionPolicy::advertisesExtension()
    || !\PHPCompiler\ext\mailparse\MailparseExtensionPolicy::advertisesExtension()) {
    die('skip gnupg/mailparse withheld');
}
?>
--FILE--
<?php
declare(strict_types=1);
echo extension_loaded('gnupg') ? "gnupg=1\n" : "gnupg=0\n";
foreach ([
    'GNUPG_SIGSUM_VALID', 'GNUPG_SIGSUM_GREEN', 'GNUPG_SIG_MODE_NORMAL',
    'GNUPG_ERROR_EXCEPTION', 'GNUPG_PROTOCOL_OpenPGP', 'GNUPG_VALIDITY_FULL',
] as $c) {
    echo $c, '=', defined($c) ? var_export(constant($c), true) : 'MISSING', "\n";
}
echo extension_loaded('mailparse') ? "mailparse=1\n" : "mailparse=0\n";
foreach ([
    'MAILPARSE_EXTRACT_OUTPUT', 'MAILPARSE_EXTRACT_STREAM', 'MAILPARSE_EXTRACT_RETURN',
] as $c) {
    echo $c, '=', defined($c) ? var_export(constant($c), true) : 'MISSING', "\n";
}
?>
--EXPECT--
gnupg=1
GNUPG_SIGSUM_VALID=1
GNUPG_SIGSUM_GREEN=2
GNUPG_SIG_MODE_NORMAL=0
GNUPG_ERROR_EXCEPTION=2
GNUPG_PROTOCOL_OpenPGP=0
GNUPG_VALIDITY_FULL=4
mailparse=1
MAILPARSE_EXTRACT_OUTPUT=0
MAILPARSE_EXTRACT_STREAM=1
MAILPARSE_EXTRACT_RETURN=2
