<?php
/**
 * #36382 — foreach ($response->getHeaders() as $name => $values) after
 * building a Nyholm-shaped response must not abort before body echo under AOT.
 */
class Resp36382
{
    private $headers = [];

    public function withHeader($name, $value): self
    {
        $new = clone $this;
        $new->headers[$name] = is_array($value) ? $value : [$value];
        return $new;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getStatusCode(): int
    {
        return 200;
    }

    public function getBody(): string
    {
        return 'hello';
    }
}

$response = (new Resp36382())->withHeader('Content-Type', 'text/plain');
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo $response->getBody();
