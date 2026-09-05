<?php
/**
 * #36382 — Untyped declared property without initializer is implicit null under AOT
 * (Zend/zend_objects.c, #22047). Nyholm Uri::$port is this shape; UNDEFINED here made
 * getPort() TypeError / fromArrays SIGSEGV after getHeadersFromServer.
 */
class U36382UntypedPort
{
    private $port;

    public function getPort()
    {
        return $this->port;
    }

    public function withPort($port)
    {
        if ($this->port === $port) {
            return $this;
        }
        $new = clone $this;
        $new->port = $port;

        return $new;
    }
}

$u = new U36382UntypedPort();
var_export($u->getPort());
echo "\n";
$u2 = $u->withPort(80);
var_export($u2->getPort());
echo "\n";
$u3 = $u->withPort(null);
var_export($u3->getPort());
echo "\nok\n";
