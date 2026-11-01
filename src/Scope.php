<?php

declare(strict_types=1);

namespace Ambit;

use InvalidArgumentException;

final class Scope
{
    /** @var string[] */
    private array $paths;

    /** @var string[] */
    private array $exclusions;

    private PathMatcher $matcher;

    public static function fromProject(string $directory, string $set = 'php'): self
    {
        $file = rtrim($directory, '/\\') . '/ambit.yaml';
        return new self((new YamlConfigLoader())->load($file, $set));
    }

    public function __construct(Config $config)
    {
        $this->paths = $config->paths();
        $this->exclusions = $config->exclusions();
        $this->matcher = new PathMatcher();

        // Rector validates literal inclusions, but permits unmatched wildcard paths.
        foreach ($this->paths as $path) {
            if (strpos($path, '*') === false && !file_exists($path)) {
                throw new InvalidArgumentException('Configured source path does not exist: ' . $path);
            }
        }
    }

    /** @return string[] Absolute paths/patterns for Rector's withPaths(). */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return string[] Absolute path patterns for Rector's withSkip(). */
    public function exclusions(): array
    {
        return $this->exclusions;
    }

    /** @param string[] $exclusions Absolute exclusion patterns. */
    public function withAdditionalExclusions(array $exclusions): self
    {
        return new self(new Config($this->paths, array_merge($this->exclusions, $exclusions)));
    }

    /** @return string[] Existing files/directories after Rector-compatible glob expansion. */
    public function resolvePaths(): array
    {
        $resolved = [];
        foreach ($this->paths as $path) {
            $matches = strpos($path, '*') !== false ? (glob($path) ?: []) : [$path];
            foreach ($matches as $match) {
                $realPath = realpath($match);
                if ($realPath !== false) {
                    $resolved[str_replace('\\', '/', $realPath)] = true;
                }
            }
        }
        return array_keys($resolved);
    }

    public function isExcluded(string $file): bool
    {
        foreach ($this->exclusions as $pattern) {
            if ($this->matcher->matches($file, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
