<?php

declare(strict_types=1);

namespace Ambit;

use InvalidArgumentException;

final class Config
{
    /** @var string[] */
    private array $paths;

    /** @var string[] */
    private array $exclusions;

    /**
     * @param string[] $paths Absolute paths/patterns.
     * @param string[] $exclusions Absolute exclusion patterns.
     */
    public function __construct(array $paths, array $exclusions = [])
    {
        $this->paths = $this->normalize($paths, 'paths');
        $this->exclusions = $this->normalize($exclusions, 'exclude');
    }

    /** @return string[] Absolute paths/patterns, before wildcard expansion. */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return string[] Absolute exclusion patterns. */
    public function exclusions(): array
    {
        return $this->exclusions;
    }

    /**
     * @param string[] $entries
     * @return string[]
     */
    private function normalize(array $entries, string $name): array
    {
        if (array_values($entries) !== $entries) {
            throw new InvalidArgumentException('Scope "' . $name . '" must be a list of paths.');
        }
        $normalized = [];
        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new InvalidArgumentException('Scope "' . $name . '" must contain non-empty strings.');
            }
            $entry = str_replace('\\', '/', $entry);
            if (!preg_match('~^(?:/|[a-zA-Z]:/)~', $entry)) {
                throw new InvalidArgumentException('Scope "' . $name . '" must contain absolute paths.');
            }
            $normalized[] = $entry;
        }
        return array_values(array_unique($normalized));
    }
}
