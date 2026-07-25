--TEST--
intl formatter/calendar serialize()/unserialize() reject (issue #23092, ext/intl/*.stub.php)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Intl OOP withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
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
        echo $n, ' unserialize ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
IntlDateFormatter Exception:Serialization of 'IntlDateFormatter' is not allowed
NumberFormatter Exception:Serialization of 'NumberFormatter' is not allowed
Collator Exception:Serialization of 'Collator' is not allowed
MessageFormatter Exception:Serialization of 'MessageFormatter' is not allowed
ResourceBundle Exception:Serialization of 'ResourceBundle' is not allowed
IntlCalendar Exception:Serialization of 'IntlGregorianCalendar' is not allowed
IntlDateFormatter unserialize Exception:Unserialization of 'IntlDateFormatter' is not allowed
NumberFormatter unserialize Exception:Unserialization of 'NumberFormatter' is not allowed
Collator unserialize Exception:Unserialization of 'Collator' is not allowed
MessageFormatter unserialize Exception:Unserialization of 'MessageFormatter' is not allowed
ResourceBundle unserialize Exception:Unserialization of 'ResourceBundle' is not allowed
IntlGregorianCalendar unserialize Exception:Unserialization of 'IntlGregorianCalendar' is not allowed
IntlCalendar unserialize Exception:Unserialization of 'IntlCalendar' is not allowed
