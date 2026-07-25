--TEST--
intl transliterator/spoof/uconverter/timezone/breakiterator unserialize deny (issue #23136)
--FILE--
<?php
foreach ([
    'Transliterator' => 'O:14:"Transliterator":0:{}',
    'Spoofchecker' => 'O:12:"Spoofchecker":0:{}',
    'UConverter' => 'O:10:"UConverter":0:{}',
    'IntlTimeZone' => 'O:12:"IntlTimeZone":0:{}',
    'IntlBreakIterator' => 'O:17:"IntlBreakIterator":0:{}',
    'IntlRuleBasedBreakIterator' => 'O:26:"IntlRuleBasedBreakIterator":0:{}',
    'IntlCodePointBreakIterator' => 'O:26:"IntlCodePointBreakIterator":0:{}',
    'IntlPartsIterator' => 'O:17:"IntlPartsIterator":0:{}',
] as $label => $payload) {
    try {
        unserialize($payload);
        echo $label, ":unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':unserialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
Transliterator:unserialize:Exception:Unserialization of 'Transliterator' is not allowed
Spoofchecker:unserialize:Exception:Unserialization of 'Spoofchecker' is not allowed
UConverter:unserialize:Exception:Unserialization of 'UConverter' is not allowed
IntlTimeZone:unserialize:Exception:Unserialization of 'IntlTimeZone' is not allowed
IntlBreakIterator:unserialize:Exception:Unserialization of 'IntlBreakIterator' is not allowed
IntlRuleBasedBreakIterator:unserialize:Exception:Unserialization of 'IntlRuleBasedBreakIterator' is not allowed
IntlCodePointBreakIterator:unserialize:Exception:Unserialization of 'IntlCodePointBreakIterator' is not allowed
IntlPartsIterator:unserialize:Exception:Unserialization of 'IntlPartsIterator' is not allowed
