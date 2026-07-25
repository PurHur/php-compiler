<?php

$cases = [
    'Transliterator' => fn () => Transliterator::create('Any-Latin'),
    'Spoofchecker' => fn () => new Spoofchecker(),
    'UConverter' => fn () => new UConverter('utf-8', 'utf-8'),
    'IntlTimeZone' => fn () => IntlTimeZone::createTimeZone('UTC'),
    'IntlRuleBasedBreakIterator' => fn () => IntlBreakIterator::createWordInstance(),
];
foreach ($cases as $label => $mk) {
    try {
        $o = $mk();
        if ($label === 'IntlRuleBasedBreakIterator') {
            $parts = $o->getPartsIterator();
        }
        serialize($o);
        echo $label, ":serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':serialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
if (isset($parts)) {
    try {
        serialize($parts);
        echo "IntlPartsIterator:serialize:no-throw\n";
    } catch (Throwable $e) {
        echo 'IntlPartsIterator:serialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
foreach ([
    'Transliterator' => 'O:14:"Transliterator":0:{}',
    'Spoofchecker' => 'O:12:"Spoofchecker":0:{}',
    'UConverter' => 'O:10:"UConverter":0:{}',
    'IntlTimeZone' => 'O:12:"IntlTimeZone":0:{}',
    'IntlBreakIterator' => 'O:17:"IntlBreakIterator":0:{}',
    'IntlRuleBasedBreakIterator' => 'O:26:"IntlRuleBasedBreakIterator":0:{}',
    'IntlPartsIterator' => 'O:17:"IntlPartsIterator":0:{}',
] as $label => $payload) {
    try {
        unserialize($payload);
        echo $label, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':unserialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
