<?php
/**
 * #6514 / #28516 — getLazyInitializationException is phantom vs php-src.
 */
echo 'getLazyInitializationException=', method_exists(ReflectionClass::class, 'getLazyInitializationException') ? '1' : '0', "\n";
echo 'getLazyInitializer=', method_exists(ReflectionClass::class, 'getLazyInitializer') ? '1' : '0', "\n";
