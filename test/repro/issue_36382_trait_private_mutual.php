<?php
/**
 * #36382 — private trait method calling another private trait method (Nyholm MessageTrait).
 * php-src: Zend/zend_traits.c (fn->common.scope = composing class after copy)
 */
namespace N;

trait T
{
    private function setHeaders(array $headers): void
    {
        foreach ($headers as $header => $value) {
            if (\is_int($header)) {
                $header = (string) $header;
            }
            $value = $this->validateAndTrimHeader($header, $value);
            $this->headers[$header] = $value;
        }
    }

    /** @var array<string, list<string>> */
    private $headers = [];

    private function validateAndTrimHeader($header, $values): array
    {
        if (!\is_string($header) || 1 !== \preg_match("@^[!#$%&'*+.^_`|~0-9A-Za-z-]+$@D", $header)) {
            throw new \InvalidArgumentException('bad name');
        }
        if (!\is_array($values)) {
            return [\trim((string) $values, " \t")];
        }

        return $values;
    }

    public function getLine(string $header): string
    {
        return isset($this->headers[$header]) ? \implode(', ', $this->headers[$header]) : '';
    }
}

class C
{
    use T;

    public function __construct(array $h = [])
    {
        $this->setHeaders($h);
    }
}

$c = new C(['Content-Type' => 'text/plain']);
echo $c->getLine('Content-Type'), "\n";
