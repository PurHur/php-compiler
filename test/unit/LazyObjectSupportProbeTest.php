<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Lazy object procedural probe helpers (#6052, #6097). */
final class LazyObjectSupportProbeTest extends TestCase
{
    public function testPlainObjectHasNoProbes(): void
    {
        $class = new ClassEntry('Svc');
        $plain = new ObjectEntry($class);
        $plain->constructed = true;

        $this->assertFalse(LazyObjectSupport::hasLazyObjectInitializer($plain));
        $this->assertFalse(LazyObjectSupport::hasLazyObjectUninitializer($plain));
    }

    public function testGhostWithoutInitializerHasNoInitializerProbe(): void
    {
        $class = new ClassEntry('Svc');
        $ghost = LazyObjectSupport::createGhost($class, null);

        $this->assertFalse(LazyObjectSupport::hasLazyObjectInitializer($ghost));
        $this->assertFalse(LazyObjectSupport::hasLazyObjectUninitializer($ghost));
    }

    public function testMarkAsInitializedClearsPendingState(): void
    {
        $class = new ClassEntry('Svc');
        $class->properties[] = new ClassProperty('id', null, new Variable());
        $ghost = LazyObjectSupport::createGhost($class, null);
        $this->assertTrue(LazyObjectSupport::isUninitializedLazyObject($ghost));

        LazyObjectSupport::markAsInitialized($ghost);

        $this->assertFalse(LazyObjectSupport::hasLazyObjectInitializer($ghost));
        $this->assertFalse(LazyObjectSupport::hasLazyObjectUninitializer($ghost));
    }
}
