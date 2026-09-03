<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\Config;

/**
 * AOT / helper-cache compile target (#36391).
 *
 * Selects the helper-runtime archive directory, LLVM triple/CPU/reloc/data-layout
 * metadata, and Linker toolchain paths from {@see ENV} (or the host when unset).
 * Cross-link (target ≠ host) is refused until an arm64 sysroot lands.
 */
final class CompileTarget
{
    public const ENV = 'PHP_COMPILER_TARGET';

    public const ID_X86_64_LINUX = 'x86_64-linux';
    public const ID_AARCH64_LINUX = 'aarch64-linux';
    public const ID_AARCH64_DARWIN = 'aarch64-darwin';

    /** @var array<string, array{
     *   triple: string,
     *   cpu: string,
     *   reloc: string,
     *   data_layout: string,
     *   multiarch_lib: ?string,
     *   dynamic_linker: ?string,
     *   crt_dir: ?string,
     *   include_multiarch: ?string,
     *   link_native: bool
     * }> */
    private const SPECS = [
        self::ID_X86_64_LINUX => [
            'triple' => 'x86_64-unknown-linux-gnu',
            'cpu' => 'generic',
            'reloc' => 'pic',
            'data_layout' => 'e-m:e-p270:32:32-p271:32:32-p272:64:64-i64:64-f80:128-n8:16:32:64-S128',
            'multiarch_lib' => '/usr/lib/x86_64-linux-gnu',
            'dynamic_linker' => '/lib64/ld-linux-x86-64.so.2',
            'crt_dir' => '/usr/lib/x86_64-linux-gnu',
            'include_multiarch' => '/usr/include/x86_64-linux-gnu',
            'link_native' => true,
        ],
        self::ID_AARCH64_LINUX => [
            'triple' => 'aarch64-unknown-linux-gnu',
            'cpu' => 'generic',
            'reloc' => 'pic',
            'data_layout' => 'e-m:e-i8:8:32-i16:16:32-i64:64-i128:128-n32:64-S128',
            'multiarch_lib' => '/usr/lib/aarch64-linux-gnu',
            'dynamic_linker' => '/lib/ld-linux-aarch64.so.1',
            'crt_dir' => '/usr/lib/aarch64-linux-gnu',
            'include_multiarch' => '/usr/include/aarch64-linux-gnu',
            'link_native' => true,
        ],
        self::ID_AARCH64_DARWIN => [
            // LLVM 9 on macOS is deferred to the LLVM 22 migration (#36220 / #36391).
            'triple' => 'aarch64-apple-darwin',
            'cpu' => 'generic',
            'reloc' => 'pic',
            'data_layout' => 'e-m:o-i64:64-i128:128-n32:64-S128',
            'multiarch_lib' => null,
            'dynamic_linker' => null,
            'crt_dir' => null,
            'include_multiarch' => null,
            'link_native' => false,
        ],
    ];

    /** @var array<string, string> */
    private const ALIASES = [
        'amd64-linux' => self::ID_X86_64_LINUX,
        'x86_64-unknown-linux-gnu' => self::ID_X86_64_LINUX,
        'arm64-linux' => self::ID_AARCH64_LINUX,
        'aarch64-unknown-linux-gnu' => self::ID_AARCH64_LINUX,
        'arm64-darwin' => self::ID_AARCH64_DARWIN,
        'aarch64-apple-darwin' => self::ID_AARCH64_DARWIN,
    ];

    private static ?self $cached = null;

    private function __construct(
        private readonly string $id,
        /** @var array{triple: string, cpu: string, reloc: string, data_layout: string, multiarch_lib: ?string, dynamic_linker: ?string, crt_dir: ?string, include_multiarch: ?string, link_native: bool} */
        private readonly array $spec,
    ) {
    }

    /** Reset process cache (unit tests). */
    public static function resetCache(): void
    {
        self::$cached = null;
    }

    public static function current(): self
    {
        if (null !== self::$cached) {
            return self::$cached;
        }

        return self::$cached = self::resolve(self::envOrNull());
    }

    /**
     * Resolve an explicit id, or the host when $id is null/empty.
     *
     * @throws \InvalidArgumentException unknown target id
     */
    public static function resolve(?string $id): self
    {
        $normalized = self::normalizeId($id);
        if (null === $normalized) {
            $normalized = self::hostId();
        }
        if (!isset(self::SPECS[$normalized])) {
            if (null !== $id && '' !== trim($id)) {
                throw new \InvalidArgumentException(
                    'Unknown '.self::ENV.'='.$id.'; expected one of: '
                    .implode(', ', array_keys(self::SPECS))
                    .' (#36391)'
                );
            }

            // Unset env on an unsupported host: keep helper-cache path = hostId, refuse link.
            return new self($normalized, [
                'triple' => $normalized,
                'cpu' => 'generic',
                'reloc' => 'pic',
                'data_layout' => '',
                'multiarch_lib' => null,
                'dynamic_linker' => null,
                'crt_dir' => null,
                'include_multiarch' => null,
                'link_native' => false,
            ]);
        }

        return new self($normalized, self::SPECS[$normalized]);
    }

    /** Canonical helper-cache / prelinked directory key (e.g. x86_64-linux). */
    public function id(): string
    {
        return $this->id;
    }

    public function llvmTriple(): string
    {
        return $this->spec['triple'];
    }

    public function cpu(): string
    {
        return $this->spec['cpu'];
    }

    /**
     * LLVMGetTargetFromName arch token for {@see createTargetMachine} (#36387).
     * Prefer this over {@see \PHPLLVM\LLVM::getTargetFromTriple} (missing LLVMTargetRef import).
     */
    public function llvmTargetName(): string
    {
        return match ($this->id) {
            self::ID_AARCH64_LINUX, self::ID_AARCH64_DARWIN => 'aarch64',
            default => 'x86-64',
        };
    }

    /**
     * php-llvm {@see \PHPLLVM\LLVM::initialize} TargetSet class name (X86 / AArch64).
     *
     * {@see \PHPLLVM\LLVM::initializeNative} only loads the host TargetSet; AArch64::isNative()
     * is hard-false in php-llvm, so cross (and even native aarch64) emit must call this.
     */
    public function llvmBackendName(): ?string
    {
        return match ($this->id) {
            self::ID_AARCH64_LINUX, self::ID_AARCH64_DARWIN => 'AArch64',
            self::ID_X86_64_LINUX => 'X86',
            default => null,
        };
    }

    /**
     * Initialize host + this target's LLVM backend so {@see llvmTargetName} is registered.
     *
     * @throws \RuntimeException when a non-native target's backend is missing from libLLVM
     */
    public function initializeLlvm(\PHPLLVM\LLVM $llvm): void
    {
        $llvm->initializeNative();
        $backend = $this->llvmBackendName();
        if (null === $backend) {
            return;
        }
        try {
            $llvm->initialize($backend);
        } catch (\LogicException $e) {
            if (!$this->isHostNative()) {
                throw new \RuntimeException(
                    'LLVM backend '.$backend.' is not available for target '.$this->id()
                    .' (#36391): '.$e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    /** Triple + data-layout on an LLVM module before types/codegen (AOT standalone). */
    public function applyToModule(\PHPLLVM\Module $module): void
    {
        if (!isset(self::SPECS[$this->id])) {
            return;
        }
        $module->setTarget($this->llvmTriple());
        $layout = $this->dataLayout();
        if ('' !== $layout) {
            $module->setDataLayout($layout);
        }
    }

    /** ELF e_machine (little-endian) for Linux targets; null for Mach-O / unknown. */
    public function elfMachine(): ?int
    {
        return match ($this->id) {
            self::ID_X86_64_LINUX => 62,   // EM_X86_64
            self::ID_AARCH64_LINUX => 183, // EM_AARCH64
            default => null,
        };
    }

    /** PHPLLVM\Target::RELOC_* for {@see createTargetMachine} (#36387). */
    public function llvmRelocModeConst(): int
    {
        return match ($this->relocMode()) {
            'static' => \PHPLLVM\Target::RELOC_STATIC,
            'default' => \PHPLLVM\Target::RELOC_DEFAULT,
            default => \PHPLLVM\Target::RELOC_PIC,
        };
    }

    /** Reloc model name for LLVMCreateTargetMachine (pic|static|default). */
    public function relocMode(): string
    {
        return $this->spec['reloc'];
    }

    public function dataLayout(): string
    {
        return $this->spec['data_layout'];
    }

    /** Host multiarch lib dir for -L, or null when the target has no Linux multiarch layout. */
    public function multiarchLibDir(): ?string
    {
        return $this->spec['multiarch_lib'];
    }

    public function hostLibSearchFlag(): string
    {
        $dir = $this->multiarchLibDir();

        return null === $dir ? '' : '-L'.$dir;
    }

    public function dynamicLinker(): ?string
    {
        return $this->spec['dynamic_linker'];
    }

    public function crtDir(): ?string
    {
        return $this->spec['crt_dir'];
    }

    public function includeMultiarchDir(): ?string
    {
        return $this->spec['include_multiarch'];
    }

    /** Absolute path under prelinked/helper-runtime/<id>. */
    public function helperRuntimeArchDir(string $repoRoot): string
    {
        return rtrim($repoRoot, '/').'/prelinked/helper-runtime/'.$this->id;
    }

    public function isHostNative(): bool
    {
        return $this->id === self::hostId();
    }

    /**
     * Whether Linker may produce a runnable binary for this target on this host.
     * Darwin aarch64 is data-only until LLVM 22 (#36220).
     */
    public function canLinkOnThisHost(): bool
    {
        return $this->spec['link_native'] && $this->isHostNative();
    }

    /**
     * @throws \RuntimeException when cross-link / unsupported target would produce a broken binary
     */
    public function assertCanLinkOnThisHost(): void
    {
        if ($this->canLinkOnThisHost()) {
            return;
        }
        throw new \RuntimeException(
            'AOT link for target '.$this->id.' is not supported on host '.self::hostId()
            .' (PHP_COMPILER_TARGET / #36391). Use a matching host, or unset '
            .self::ENV.' to build for the native arch.'
        );
    }

    /** @return list<string> */
    public static function knownIds(): array
    {
        return array_keys(self::SPECS);
    }

    public static function hostId(): string
    {
        $machine = self::normalizeMachine(php_uname('m'));
        $os = strtolower(php_uname('s'));
        if ('linux' === $os) {
            if ('x86_64' === $machine) {
                return self::ID_X86_64_LINUX;
            }
            if ('aarch64' === $machine) {
                return self::ID_AARCH64_LINUX;
            }
        }
        if ('darwin' === $os && 'aarch64' === $machine) {
            return self::ID_AARCH64_DARWIN;
        }

        // Preserve prior php_uname shape for exotic hosts so helper-cache paths stay stable.
        return $machine.'-'.$os;
    }

    private static function envOrNull(): ?string
    {
        $v = Config::getenv(self::ENV);
        if (false === $v || '' === $v) {
            return null;
        }

        return $v;
    }

    private static function normalizeId(?string $id): ?string
    {
        if (null === $id) {
            return null;
        }
        $id = strtolower(trim($id));
        if ('' === $id) {
            return null;
        }
        if (isset(self::ALIASES[$id])) {
            return self::ALIASES[$id];
        }
        if (isset(self::SPECS[$id])) {
            return $id;
        }

        return $id;
    }

    private static function normalizeMachine(string $machine): string
    {
        $machine = strtolower($machine);

        return match ($machine) {
            'amd64', 'x64' => 'x86_64',
            'arm64' => 'aarch64',
            default => $machine,
        };
    }
}
