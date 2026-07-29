<?php
// Protected method call from trait
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
echo "\n";

// Protected property access from trait
trait HasName {
    public function greet(): string {
        return "Hello, " . $this->name;
    }
}
class Person {
    use HasName;
    protected string $name;
    public function __construct(string $name) {
        $this->name = $name;
    }
}
$p = new Person("Alice");
echo $p->greet() . "\n";

// Private trait method should still scope to trait
trait PrivateTrait {
    private function secret(): string {
        return "secret";
    }
    public function reveal(): string {
        return $this->secret();
    }
}
class Box {
    use PrivateTrait;
}
$b = new Box();
echo $b->reveal() . "\n";

// get_class inside trait method should return using class
trait ClassInfo {
    public function whoAmI(): string {
        return get_class($this);
    }
}
class Widget {
    use ClassInfo;
}
$w = new Widget();
echo $w->whoAmI() . "\n";
