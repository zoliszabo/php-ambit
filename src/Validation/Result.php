<?php

declare(strict_types=1);

namespace Ambit\Validation;

final class Result
{
    /** @var Issue[] */
    private array $issues;

    /** @param Issue[] $issues */
    public function __construct(array $issues = [])
    {
        $this->issues = array_values($issues);
    }

    /** @return Issue[] */
    public function issues(): array
    {
        return $this->issues;
    }

    /** @return Issue[] */
    public function errors(): array
    {
        return $this->withSeverity('error');
    }

    /** @return Issue[] */
    public function warnings(): array
    {
        return $this->withSeverity('warning');
    }

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /** @return Issue[] */
    private function withSeverity(string $severity): array
    {
        return array_values(array_filter($this->issues, static function (Issue $issue) use ($severity): bool {
            return $issue->severity() === $severity;
        }));
    }
}
