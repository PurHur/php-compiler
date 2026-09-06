<?php
interface U { public function getHost(): string; }
final class UO implements U {
    public function getHost(): string { return 'h'; }
    public function __toString(): string { return 'TOSTRING'; }
}
trait M {
    /** @var array */ private $headers = ['H'=>'v'];
    /** @var array */ private $headerNames = ['h'=>'H'];
    /** @var string */ private $protocol = '1.1';
    /** @var object|null */ private $stream;
}
trait R {
    /** @var string */ private $method;
    /** @var string|null */ private $requestTarget;
    /** @var U|null */ private $uri;
    public function dump(string $label): void {
        echo "$label method=", var_export($this->method, true),
            " protocol=", var_export($this->protocol, true),
            " uri=", (is_object($this->uri) ? ('obj:'.get_class($this->uri)) : var_export($this->uri, true)),
            " headers=", var_export($this->headers, true),
            " rt=", var_export($this->requestTarget, true),
            "\n";
    }
}
final class C {
    use M; use R;
    public function __construct(string $method, U $uri) {
        $this->dump('before');
        $this->method = $method;
        $this->dump('after_method');
        $this->uri = $uri;
        $this->dump('after_uri');
        $this->protocol = '2.0';
        $this->dump('after_protocol');
    }
}
(new C('GET', new UO()));
