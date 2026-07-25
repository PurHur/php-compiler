--TEST--
intl @not-serializable unserialize deny without host php-intl (issue #23092)
--FILE--
<?php
$payloads = [
    'IntlDateFormatter' => 'O:17:"IntlDateFormatter":0:{}',
    'NumberFormatter' => 'O:15:"NumberFormatter":0:{}',
    'Collator' => 'O:8:"Collator":0:{}',
    'MessageFormatter' => 'O:16:"MessageFormatter":0:{}',
    'ResourceBundle' => 'O:14:"ResourceBundle":0:{}',
    'IntlGregorianCalendar' => 'O:21:"IntlGregorianCalendar":0:{}',
    'IntlCalendar' => 'O:12:"IntlCalendar":0:{}',
    'Transliterator' => 'O:14:"Transliterator":0:{}',
    'Spoofchecker' => 'O:12:"Spoofchecker":0:{}',
    'UConverter' => 'O:10:"UConverter":0:{}',
    'IntlTimeZone' => 'O:12:"IntlTimeZone":0:{}',
    'IntlBreakIterator' => 'O:17:"IntlBreakIterator":0:{}',
    'IntlPartsIterator' => 'O:17:"IntlPartsIterator":0:{}',
];
foreach ($payloads as $n => $payload) {
    try {
        unserialize($payload);
        echo $n, " unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $n, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
IntlDateFormatter Exception:Unserialization of 'IntlDateFormatter' is not allowed
NumberFormatter Exception:Unserialization of 'NumberFormatter' is not allowed
Collator Exception:Unserialization of 'Collator' is not allowed
MessageFormatter Exception:Unserialization of 'MessageFormatter' is not allowed
ResourceBundle Exception:Unserialization of 'ResourceBundle' is not allowed
IntlGregorianCalendar Exception:Unserialization of 'IntlGregorianCalendar' is not allowed
IntlCalendar Exception:Unserialization of 'IntlCalendar' is not allowed
Transliterator Exception:Unserialization of 'Transliterator' is not allowed
Spoofchecker Exception:Unserialization of 'Spoofchecker' is not allowed
UConverter Exception:Unserialization of 'UConverter' is not allowed
IntlTimeZone Exception:Unserialization of 'IntlTimeZone' is not allowed
IntlBreakIterator Exception:Unserialization of 'IntlBreakIterator' is not allowed
IntlPartsIterator Exception:Unserialization of 'IntlPartsIterator' is not allowed
