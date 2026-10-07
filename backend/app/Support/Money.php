<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An amount of Malaysian Ringgit stored as an integer number of sen (1 RM = 100 sen).
 *
 * Why integers? Floats cannot represent 0.10 exactly, so 0.1 + 0.2 !== 0.3.
 * Whole numbers of sen never lose precision, so totals, discounts and refunds always add up.
 */
final readonly class Money implements JsonSerializable
{
    public const CURRENCY = 'MYR';

    private function __construct(public int $sen) {}

    public static function fromSen(int $sen): self
    {
        return new self($sen);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a decimal string such as "4602.50" without going through a float.
     */
    public static function fromRinggit(string $amount): self
    {
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $m)) {
            throw new InvalidArgumentException("Invalid money amount: {$amount}");
        }

        $sen = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return new self($m[1] === '-' ? -$sen : $sen);
    }

    public function add(self $other): self
    {
        return new self($this->sen + $other->sen);
    }

    public function subtract(self $other): self
    {
        return new self($this->sen - $other->sen);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->sen * $quantity);
    }

    /**
     * Take a percentage expressed in basis points (1% = 100 bps, 10% = 1000 bps),
     * rounding half away from zero using integer maths only.
     */
    public function percent(int $basisPoints): self
    {
        $numerator = $this->sen * $basisPoints;
        $rounded = intdiv(abs($numerator) + 5000, 10000);

        return new self($numerator < 0 ? -$rounded : $rounded);
    }

    public function min(self $other): self
    {
        return $this->sen <= $other->sen ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->sen === 0;
    }

    public function isNegative(): bool
    {
        return $this->sen < 0;
    }

    public function equals(self $other): bool
    {
        return $this->sen === $other->sen;
    }

    public function greaterThan(self $other): bool
    {
        return $this->sen > $other->sen;
    }

    public function lessThan(self $other): bool
    {
        return $this->sen < $other->sen;
    }

    /** "RM 4,602.00" */
    public function format(): string
    {
        $abs = abs($this->sen);
        $formatted = number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($this->sen < 0 ? '-' : '').'RM '.$formatted;
    }

    /** @return array{amount: int, currency: string, formatted: string} */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->sen,
            'currency' => self::CURRENCY,
            'formatted' => $this->format(),
        ];
    }
}
