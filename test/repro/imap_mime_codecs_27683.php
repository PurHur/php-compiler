<?php
/**
 * Issue #27683 — imap MIME transfer codecs when IMAP advertised.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 php bin/vm.php test/repro/imap_mime_codecs_27683.php
 */
declare(strict_types=1);

foreach ([
    'imap_base64',
    'imap_qprint',
    'imap_8bit',
    'imap_binary',
    'imap_utf8',
    'imap_mime_header_decode',
] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', PHP_EOL;
}

$hi = 'hi';
$b64 = imap_binary($hi);
echo 'binary=', $b64, PHP_EOL;
echo 'base64_rt=', imap_base64($b64), PHP_EOL;
echo 'base64_bad=', var_export(imap_base64('!!!'), true), PHP_EOL;

$qp = imap_8bit('a=b');
echo '8bit=', $qp, PHP_EOL;
echo 'qprint_rt=', imap_qprint($qp), PHP_EOL;

echo 'utf8=', imap_utf8('=?UTF-8?B?SGVsbG8=?='), PHP_EOL;
echo 'utf8_plain=', imap_utf8('plain'), PHP_EOL;

$parts = imap_mime_header_decode('=?UTF-8?B?SGVsbG8=?= world');
echo 'mime_n=', is_array($parts) ? count($parts) : 'F', PHP_EOL;
if (is_array($parts)) {
    echo 'mime0_cs=', $parts[0]->charset ?? '-', PHP_EOL;
    echo 'mime0_tx=', $parts[0]->text ?? '-', PHP_EOL;
    echo 'mime1_cs=', $parts[1]->charset ?? '-', PHP_EOL;
    echo 'mime1_tx=', $parts[1]->text ?? '-', PHP_EOL;
}

$rf = new ReflectionFunction('imap_base64');
echo 'b64_param=', $rf->getParameters()[0]->getName(), PHP_EOL;
echo 'b64_ret=', $rf->getReturnType() ? (string) $rf->getReturnType() : '-', PHP_EOL;
$rf2 = new ReflectionFunction('imap_utf8');
echo 'utf8_param=', $rf2->getParameters()[0]->getName(), PHP_EOL;
$rf3 = new ReflectionFunction('imap_mime_header_decode');
echo 'mime_ret=', $rf3->getReturnType() ? (string) $rf3->getReturnType() : '-', PHP_EOL;
