<?php
/**
 * #35831 leftover of #26863 — SimpleXMLElement instanceof Traversable (php-src simplexml.stub.php).
 */
$x = new SimpleXMLElement('<r><a/></r>');
echo 'Traversable=', $x instanceof Traversable ? '1' : '0', "\n";
echo 'Iterator=', $x instanceof Iterator ? '1' : '0', "\n";
echo 'Countable=', $x instanceof Countable ? '1' : '0', "\n";
echo 'RecursiveIterator=', $x instanceof RecursiveIterator ? '1' : '0', "\n";
echo 'Stringable=', $x instanceof Stringable ? '1' : '0', "\n";
echo 'ArrayAccess=', $x instanceof ArrayAccess ? '1' : '0', "\n";
echo 'self=', $x instanceof SimpleXMLElement ? '1' : '0', "\n";
