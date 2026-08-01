--TEST--
Dom\ living nodes deny user new — private final __construct (#26059)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomLivingStandardNamespace()) {
    die('skip Dom\\ living API requires PHP_COMPILER_PROFILE=8.4 (#26059)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    new Dom\HTMLDocument();
    echo "Dom\\HTMLDocument NEW_OK\n";
} catch (Throwable $e) {
    echo 'Dom\\HTMLDocument ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new Dom\XMLDocument();
    echo "Dom\\XMLDocument NEW_OK\n";
} catch (Throwable $e) {
    echo 'Dom\\XMLDocument ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new Dom\Element();
    echo "Dom\\Element NEW_OK\n";
} catch (Throwable $e) {
    echo 'Dom\\Element ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new Dom\Text();
    echo "Dom\\Text NEW_OK\n";
} catch (Throwable $e) {
    echo 'Dom\\Text ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new Dom\TokenList();
    echo "Dom\\TokenList NEW_OK\n";
} catch (Throwable $e) {
    echo 'Dom\\TokenList ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new Dom\NamespaceInfo();
    echo "Dom\\NamespaceInfo NEW_OK\n";
} catch (Throwable $e) {
    echo 'Dom\\NamespaceInfo ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new Dom\Node();
    echo "Dom\\Node NEW_OK\n";
} catch (Throwable $e) {
    echo 'Dom\\Node ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new Dom\Document();
    echo "Dom\\Document NEW_OK\n";
} catch (Throwable $e) {
    echo 'Dom\\Document ', get_class($e), ': ', $e->getMessage(), "\n";
}

// Collections without private ctor remain instantiable (php-src 8.4/8.5).
try {
    $o = new Dom\NodeList();
    echo 'Dom\\NodeList NEW_OK ', get_class($o), "\n";
} catch (Throwable $e) {
    echo 'Dom\\NodeList ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $o = new Dom\HTMLCollection();
    echo 'Dom\\HTMLCollection NEW_OK ', get_class($o), "\n";
} catch (Throwable $e) {
    echo 'Dom\\HTMLCollection ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $o = new Dom\NamedNodeMap();
    echo 'Dom\\NamedNodeMap NEW_OK ', get_class($o), "\n";
} catch (Throwable $e) {
    echo 'Dom\\NamedNodeMap ', get_class($e), ': ', $e->getMessage(), "\n";
}

$doc = Dom\HTMLDocument::createEmpty();
echo 'factory=', get_class($doc), "\n";
$el = $doc->createElement('p');
echo 'createElement=', get_class($el), "\n";
?>
--EXPECT--
Dom\HTMLDocument Error: Call to private Dom\Node::__construct() from global scope
Dom\XMLDocument Error: Call to private Dom\Node::__construct() from global scope
Dom\Element Error: Call to private Dom\Node::__construct() from global scope
Dom\Text Error: Call to private Dom\Node::__construct() from global scope
Dom\TokenList Error: Call to private Dom\TokenList::__construct() from global scope
Dom\NamespaceInfo Error: Call to private Dom\NamespaceInfo::__construct() from global scope
Dom\Node Error: Call to private Dom\Node::__construct() from global scope
Dom\Document Error: Cannot instantiate abstract class Dom\Document
Dom\NodeList NEW_OK Dom\NodeList
Dom\HTMLCollection NEW_OK Dom\HTMLCollection
Dom\NamedNodeMap NEW_OK Dom\NamedNodeMap
factory=Dom\HTMLDocument
createElement=Dom\HTMLElement
