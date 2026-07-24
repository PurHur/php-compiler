<?php
/**
 * Repro for #22946 — MessageFormatter missing args leave Zend-shaped {n}/{name},
 * not full ICU type/style skeletons.
 *
 * php-src: ext/intl/msgformat/msgformat_format.c
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (!class_exists('MessageFormatter')) {
    fwrite(STDERR, "skip: MessageFormatter not advertised (need extension_loaded('intl'))\n");
    exit(0);
}

$f = MessageFormatter::create('en_US', 'Item {0,number} of {1,number}');
echo 'both=', $f->format([3, 10]), "\n";
echo 'one=', $f->format([3]), "\n";
echo 'none=', $f->format([]), "\n";
$f2 = MessageFormatter::create('en_US', '{0,select,male{he}female{she}other{they}} went');
echo 'selMiss=', $f2->format([]), "\n";
$f3 = MessageFormatter::create('en_US', 'Hi {name}');
echo 'named=', $f3->format(['name' => 'Bob']), "\n";
$f4 = MessageFormatter::create('en_US', 'Hi {name,select,other{X}}');
echo 'namedMiss=', $f4->format([]), "\n";
$f5 = MessageFormatter::create('en_US', '{0,plural,one{# item} other{# items}}');
echo 'plMiss=', $f5->format([]), "\n";
echo 'proc=', msgfmt_format_message('en_US', 'Item {0,number} of {1,number}', [3]), "\n";
