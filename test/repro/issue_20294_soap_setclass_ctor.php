<?php

/**
 * Repro #20294 — SoapServer::setClass() constructor args.
 */
class Svc
{
    public $n;
    public function __construct($n = 0)
    {
        $this->n = $n;
    }
    public function get()
    {
        return $this->n;
    }
}

$req = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
    .'<SOAP-ENV:Body><get/></SOAP-ENV:Body></SOAP-ENV:Envelope>';

$server = new SoapServer(null, ['uri' => 'http://example.com/']);
$server->setClass('Svc', 42);
ob_start();
$server->handle($req);
$out = ob_get_clean();
echo 'has_42=', (is_string($out) && str_contains($out, '42')) ? 1 : 0, "\n";
