<?php
// #36382 — Nyholm-shaped trait property: RequestTrait assigns $this->headers
// declared only on MessageTrait (zend_do_traits_property_binding must not
// treat the assignment as a second declaration).
trait MessageTrait36382
{
    private $headers = [];

    private $headerNames = [];

    public function hasHeader($h): bool
    {
        return isset($this->headerNames[strtolower($h)]);
    }
}

trait RequestTrait36382
{
    private $uri;

    private function updateHostFromUri(): void
    {
        $host = 'example.com';
        if (isset($this->headerNames['host'])) {
            $header = $this->headerNames['host'];
        } else {
            $this->headerNames['host'] = $header = 'Host';
        }
        // Direct assign (same as patch-nyholm-request-trait-36382.php).
        $this->headers = [$header => [$host]];
    }

    public function bump(): void
    {
        $this->updateHostFromUri();
    }
}

class Request36382
{
    use MessageTrait36382;
    use RequestTrait36382;

    public function __construct()
    {
        $this->uri = 'x';
        $this->bump();
    }

    public function hostHeader(): string
    {
        return $this->headers['Host'][0] ?? 'MISSING';
    }
}

echo (new Request36382())->hostHeader(), "\n";
