<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Typed access to a JSON (or form) body; a wrong value throws ApiError 422 naming the field.
 */
final class Input
{
    private function __construct(private array $data)
    {
    }

    public static function json(Request $request): self
    {
        if ($request->getContent() === '') {
            return new self([]);
        }
        try {
            $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiError::invalid('Niepoprawny JSON.');
        }
        if (!is_array($data)) {
            throw ApiError::invalid('Oczekiwano obiektu JSON.');
        }
        return new self($data);
    }

    public static function form(Request $request): self
    {
        return new self($request->request->all());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null && $this->data[$key] !== '';
    }

    public function string(string $key, int $max = 200, bool $required = true): ?string
    {
        if (!$this->has($key)) {
            return $required ? throw ApiError::invalid("Pole „{$key}” jest wymagane.") : null;
        }
        $value = $this->data[$key];
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw ApiError::invalid("Pole „{$key}” musi być tekstem.");
        }
        $value = trim((string) $value);
        if ($value === '' && $required) {
            throw ApiError::invalid("Pole „{$key}” jest wymagane.");
        }
        if (mb_strlen($value) > $max) {
            throw ApiError::invalid("Pole „{$key}” może mieć najwyżej {$max} znaków.");
        }
        return $value;
    }

    public function number(string $key, float $min, float $max, bool $required = true): ?float
    {
        if (!$this->has($key)) {
            return $required ? throw ApiError::invalid("Pole „{$key}” jest wymagane.") : null;
        }
        $value = $this->data[$key];
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (!is_numeric($value) || $value < $min || $value > $max) {
            throw ApiError::invalid("Pole „{$key}” musi być liczbą od {$min} do {$max}.");
        }
        return (float) $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? false;
        return $value === true || $value === 'true' || $value === '1' || $value === 1;
    }

    /** @param list<string> $allowed */
    public function choice(string $key, array $allowed, bool $required = true): ?string
    {
        $value = $this->string($key, 50, $required);
        if ($value !== null && !in_array($value, $allowed, true)) {
            throw ApiError::invalid("Pole „{$key}” ma niedozwoloną wartość.");
        }
        return $value;
    }

    public function date(string $key, bool $required = true): ?\DateTimeImmutable
    {
        $value = $this->string($key, 10, $required);
        if ($value === null) {
            return null;
        }
        return Dates::day($value) ?? throw ApiError::invalid("Pole „{$key}” musi być datą RRRR-MM-DD.");
    }

    /** An ISO 8601 moment ("2026-10-13T19:05:00+02:00"); null if absent. */
    public function moment(string $key): ?\DateTimeImmutable
    {
        $value = $this->string($key, 40, false);
        if ($value === null) {
            return null;
        }
        $at = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $value)
            ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $value);
        if ($at === false) {
            throw ApiError::invalid("Pole „{$key}” musi być datą i godziną ISO 8601.");
        }
        return $at;
    }

    /** @return list<mixed> */
    public function list(string $key, int $max): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw ApiError::invalid("Pole „{$key}” musi być listą.");
        }
        if (count($value) > $max) {
            throw ApiError::invalid("Lista „{$key}” może mieć najwyżej {$max} pozycji.");
        }
        return $value;
    }

    public static function of(mixed $data): self
    {
        if (!is_array($data)) {
            throw ApiError::invalid('Oczekiwano obiektu.');
        }
        return new self($data);
    }
}
