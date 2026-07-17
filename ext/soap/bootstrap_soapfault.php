<?php

declare(strict_types=1);

/**
 * Native SoapFault for VM ThrowableManifest / host throws (php-src ext/soap/soap.c; #20124).
 */
if (!\class_exists('SoapFault', false)) {
    class SoapFault extends Exception
    {
        public ?string $faultcode = null;

        public string $faultstring = '';

        public ?string $faultactor = null;

        public mixed $detail = null;

        public ?string $_name = null;

        public mixed $headerfault = null;

        public string $lang = '';

        public function __construct(
            array|string|null $code = null,
            string $string = '',
            ?string $actor = null,
            mixed $details = null,
            ?string $name = null,
            mixed $headerFault = null,
            string $lang = ''
        ) {
            if (\is_array($code)) {
                $this->faultcode = isset($code[1]) ? (string) $code[1] : (isset($code[0]) ? (string) $code[0] : '');
            } else {
                $this->faultcode = null === $code ? null : (string) $code;
            }
            $this->faultstring = $string;
            $this->faultactor = $actor;
            $this->detail = $details;
            $this->_name = $name;
            $this->headerfault = $headerFault;
            $this->lang = $lang;
            parent::__construct('' !== $string ? $string : (string) ($this->faultcode ?? ''));
        }

        public function __toString(): string
        {
            return 'SoapFault exception: ['.$this->faultcode.'] '.$this->faultstring
                .' in '.$this->getFile().':'.$this->getLine()."\nStack trace:\n".$this->getTraceAsString();
        }
    }
}
