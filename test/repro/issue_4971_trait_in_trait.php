<?php
trait Base {
    public function base(): string {
        return 'base';
    }
}
trait Composed {
    use Base;
}
class C {
    use Composed;
}
echo (new C())->base(), "\n";
