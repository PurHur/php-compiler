--TEST--
Language: user class implements Throwable — runtime fatal (#25869, Zend/zend_exceptions.c)
--FILE--
<?php
echo "before\n";
class X implements Throwable {
  public function getMessage(): string { return ""; }
  public function getCode() { return 0; }
  public function getFile(): string { return ""; }
  public function getLine(): int { return 0; }
  public function getTrace(): array { return []; }
  public function getTraceAsString(): string { return ""; }
  public function getPrevious(): ?Throwable { return null; }
  public function __toString(): string { return ""; }
}
echo "reach\n";
--EXPECTF--
before

Fatal error: Class X cannot implement interface Throwable, extend Exception or Error instead in %s on line %d
--EXPECT_EXIT--
255
