<?php
trait Validatable {
    abstract protected function validate(): bool;
    public function isValid(): bool {
        return $this->validate();
    }
}
class Email {
    use Validatable;
    public function __construct(private string $value) {}
    protected function validate(): bool {
        return str_contains($this->value, '@');
    }
}
$e = new Email('test@example.com');
echo $e->isValid() ? "valid" : "invalid";
