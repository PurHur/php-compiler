--TEST--
DOMDocument/DOMElement/DOMDocumentFragment serialize()/unserialize() deny with subclass clause (issue #23073, ext/dom/node.c)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<a/>');

foreach ([
    'DOMDocument' => $dom,
    'DOMElement' => $dom->documentElement,
    'DOMDocumentFragment' => $dom->createDocumentFragment(),
] as $label => $v) {
    try {
        serialize($v);
        echo $label, ":serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $label, ':serialize:', get_class($e), ':', $e->getMessage(), "\n";
    }
}

try {
    unserialize('O:11:"DOMDocument":0:{}');
    echo "unserialize-doc:no-throw\n";
} catch (Throwable $e) {
    echo 'unserialize-doc:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:10:"DOMElement":0:{}');
    echo "unserialize-el:no-throw\n";
} catch (Throwable $e) {
    echo 'unserialize-el:', get_class($e), ':', $e->getMessage(), "\n";
}

class MyDoc extends DOMDocument
{
    public function __serialize(): array
    {
        return [];
    }

    public function __unserialize(array $data): void
    {
    }
}
$mine = new MyDoc();
$mine->loadXML('<b/>');
$wire = serialize($mine);
echo 'subclass:', str_starts_with($wire, 'O:5:"MyDoc":') ? 'ok' : $wire, "\n";
$back = unserialize($wire);
echo 'subclass-back:', $back instanceof MyDoc ? 'yes' : 'no', "\n";

class BareDoc extends DOMDocument
{
}
$bare = new BareDoc();
$bare->loadXML('<c/>');
try {
    serialize($bare);
    echo "bare:no-throw\n";
} catch (Throwable $e) {
    echo 'bare:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
DOMDocument:serialize:Exception:Serialization of 'DOMDocument' is not allowed, unless serialization methods are implemented in a subclass
DOMElement:serialize:Exception:Serialization of 'DOMElement' is not allowed, unless serialization methods are implemented in a subclass
DOMDocumentFragment:serialize:Exception:Serialization of 'DOMDocumentFragment' is not allowed, unless serialization methods are implemented in a subclass
unserialize-doc:Exception:Unserialization of 'DOMDocument' is not allowed, unless unserialization methods are implemented in a subclass
unserialize-el:Exception:Unserialization of 'DOMElement' is not allowed, unless unserialization methods are implemented in a subclass
subclass:ok
subclass-back:yes
bare:Exception:Serialization of 'BareDoc' is not allowed, unless serialization methods are implemented in a subclass
