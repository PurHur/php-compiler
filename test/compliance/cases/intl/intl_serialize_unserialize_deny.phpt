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
