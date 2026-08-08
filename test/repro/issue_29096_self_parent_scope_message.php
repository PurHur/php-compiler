<?php
/**
 * #29096 — eval(self::/parent::/static::) with no class scope must match Zend
 * "Cannot access … when no class scope is active" (zend_execute.c).
 */
try { eval("self::foo();"); } catch (Throwable $e) { echo "self:", $e->getMessage(), "\n"; }
try { eval("parent::foo();"); } catch (Throwable $e) { echo "parent:", $e->getMessage(), "\n"; }
try { eval("static::foo();"); } catch (Throwable $e) { echo "static:", $e->getMessage(), "\n"; }
