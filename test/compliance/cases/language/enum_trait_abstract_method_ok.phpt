--TEST--
Language: enum use Trait — concrete method satisfies trait abstract (#6640)
--FILE--
<?php
trait T {
    abstract public function f(): string;
}

enum E: string {
    case A = 'a';
    use T;

    public function f(): string {
        return 'ok';
    }
}

echo E::A->f(), "\n";
--EXPECT--
ok
