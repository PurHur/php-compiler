<?php
namespace Psr\Log {
trait LoggerTrait {
    public function emergency($message, array $context = []): void {
        $this->log("emergency", $message, $context);
    }
    abstract public function log($level, $message, array $context = []): void;
}
abstract class AbstractLogger {
    use LoggerTrait;
}
class NullLogger extends AbstractLogger {
    public function log($level, $message, array $context = []): void {
        echo "ok";
    }
}
}
namespace {
use Psr\Log\NullLogger;
(new NullLogger())->emergency("x");
}
