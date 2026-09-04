<?php
// Psr\Log shape: trait used by abstract class; concrete subclass implements log().
// Slim failure: Call to undefined method Psr\Log\LoggerTrait::log() (#36382).
// php-src: Zend/zend_compile.c trait flatten; zend_std_get_method on $this.
trait LoggerTrait {
    public function error(string $message): void {
        $this->log('error', $message);
    }
    abstract public function log(string $level, string $message): void;
}
abstract class AbstractLogger {
    use LoggerTrait;
}
class Logger extends AbstractLogger {
    public function log(string $level, string $message): void {
        echo $level, ':', $message;
    }
}
(new Logger())->error('boom');
