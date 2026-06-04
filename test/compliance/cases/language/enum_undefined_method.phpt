--TEST--
Language: undefined enum instance method throws catchable Error (#5676, #4827, zend_enum.c)
--FILE--
<?php
enum Status: string {
    case Ok = 'ok';
    public function label(): string {
        return $this->name;
    }
}
try {
    Status::Ok->missing();
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Call to undefined method Status::missing()
