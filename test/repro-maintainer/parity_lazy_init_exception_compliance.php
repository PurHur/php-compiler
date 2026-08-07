<?php
/**
 * #6514 / #28516 — getLazyInitializationException phantom guard.
 */
echo 'getLazyInitializationException=', method_exists(ReflectionClass::class, 'getLazyInitializationException') ? '1' : '0', "\n";
echo 'getLazyInitializer=', method_exists(ReflectionClass::class, 'getLazyInitializer') ? '1' : '0', "\n";
