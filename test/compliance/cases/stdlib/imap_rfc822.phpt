--TEST--
stdlib imap_rfc822 write/parse_adrlist/parse_headers (#27682)
--ENV--
PHP_COMPILER_ENABLE_IMAP=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::advertisesExtension()) {
    die('skip imap withheld (#27682)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo function_exists('imap_rfc822_write_address') ? '1' : '0';
echo function_exists('imap_rfc822_parse_adrlist') ? '1' : '0';
echo function_exists('imap_rfc822_parse_headers') ? '1' : '0';
echo "\n";

echo imap_rfc822_write_address('user', 'example.com', 'Name'), "\n";
echo imap_rfc822_write_address(mailbox: 'a', hostname: 'b.com', personal: ''), "\n";

$list = imap_rfc822_parse_adrlist('Name <user@example.com>', 'localhost');
echo count($list), ':', $list[0]->mailbox, '@', $list[0]->host, ':', $list[0]->personal, "\n";

$h = imap_rfc822_parse_headers("From: Alice <alice@example.com>\r\nSubject: Hi\r\n\r\n");
echo $h->subject, "\n";
echo $h->from[0]->mailbox, "\n";

$rf = new ReflectionFunction('imap_rfc822_parse_headers');
echo $rf->getParameters()[1]->getName(), '=', $rf->getParameters()[1]->getDefaultValue(), "\n";
echo $rf->hasReturnType() ? (string)$rf->getReturnType() : '-', "\n";
?>
--EXPECT--
111
Name <user@example.com>
a@b.com
1:user@example.com:Name
Hi
alice
default_hostname=UNKNOWN
stdClass
