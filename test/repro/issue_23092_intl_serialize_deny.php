<?php
foreach ([
    'IntlDateFormatter' => 'O:17:"IntlDateFormatter":0:{}',
    'NumberFormatter' => 'O:15:"NumberFormatter":0:{}',
    'Collator' => 'O:8:"Collator":0:{}',
    'MessageFormatter' => 'O:16:"MessageFormatter":0:{}',
    'ResourceBundle' => 'O:14:"ResourceBundle":0:{}',
    'IntlGregorianCalendar' => 'O:21:"IntlGregorianCalendar":0:{}',
    'IntlCalendar' => 'O:12:"IntlCalendar":0:{}',
] as $n => $payload) {
    try {
        unserialize($payload);
        echo $n, " unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $n, ' unserialize ', get_class($e), ':', $e->getMessage(), "\n";
    }
}

if (!extension_loaded('intl')) {
    echo "serialize:skip (host php-intl not loaded)\n";
    return;
}

$objs = [
    'IntlDateFormatter' => new IntlDateFormatter('en_US', IntlDateFormatter::FULL, IntlDateFormatter::FULL),
    'NumberFormatter' => new NumberFormatter('en_US', NumberFormatter::DECIMAL),
    'Collator' => new Collator('en_US'),
    'MessageFormatter' => new MessageFormatter('en_US', '{0}'),
    'ResourceBundle' => ResourceBundle::create('en', 'ICUDATA'),
    'IntlCalendar' => IntlCalendar::createInstance(),
];
foreach ($objs as $n => $o) {
    try {
        serialize($o);
        echo $n, " serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $n, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
