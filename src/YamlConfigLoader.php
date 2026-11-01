<?php

declare(strict_types=1);

namespace Ambit;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader
{
    public function load(string $file): Config
    {
        $config = Yaml::parseFile($file);
        if (!is_array($config) || !array_key_exists('paths', $config)) {
            throw new InvalidArgumentException('Scope configuration must contain a "paths" list: ' . $file);
        }
        $unknown = array_diff(array_keys($config), ['paths', 'exclude']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown scope configuration keys: ' . implode(', ', $unknown));
        }
        $config += ['exclude' => []];
        foreach (['paths', 'exclude'] as $key) {
            if (!is_array($config[$key])) {
                throw new InvalidArgumentException('Scope "' . $key . '" must be a list of paths.');
            }
        }

        $directory = realpath(dirname($file));
        if ($directory === false) {
            throw new InvalidArgumentException('Configuration directory does not exist: ' . dirname($file));
        }
        $directory = str_replace('\\', '/', $directory);
        return new Config(
            $this->resolvePaths($config['paths'], $directory),
            $this->resolvePaths($config['exclude'], $directory)
        );
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function resolvePaths(array $paths, string $directory): array
    {
        $resolved = [];
        foreach ($paths as $key => $path) {
            if (!is_string($path) || $path === '') {
                throw new InvalidArgumentException('Configuration paths must be non-empty strings.');
            }
            $path = str_replace('\\', '/', $path);
            $resolved[$key] = preg_match('~^(?:/|[a-zA-Z]:/)~', $path)
                ? $path
                : rtrim($directory, '/') . '/' . $path;
        }
        return $resolved;
    }
}
