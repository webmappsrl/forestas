<?php

namespace App\Services\RegistroCatastale;

/**
 * L'esito della lettura di una cella numerica del registro (oc:8540): vuota
 * (non si scrive, vale il DEM), letta, oppure illeggibile (non si scrive e
 * diventa un'anomalia). Vuota e illeggibile vanno distinte: solo la seconda
 * e' un problema da far correggere a Forestas.
 */
final class RegistroSanitizedValue
{
    private function __construct(
        public readonly int|float|null $value,
        private readonly bool $empty,
    ) {}

    public static function empty(): self
    {
        return new self(null, true);
    }

    public static function of(int|float $value): self
    {
        return new self($value, false);
    }

    public static function invalid(): self
    {
        return new self(null, false);
    }

    public function isEmpty(): bool
    {
        return $this->empty;
    }

    public function isInvalid(): bool
    {
        return ! $this->empty && $this->value === null;
    }
}
