<?php

declare(strict_types=1);

namespace Ambit\Tests\Unit;

use Ambit\Config;
use Ambit\FileSelector;
use Ambit\Scope;
use Ambit\YamlConfigLoader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class YamlConfigLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ambit-sets-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testPhpAndExplicitSetsApplyInheritedPathsAndExclusions(): void
    {
        $source = $this->write('project/src/example.php');
        $generated = $this->write('project/src/generated/example.php');
        $test = $this->write('project/tests/example.php');
        $fixture = $this->write('project/tests/Fixtures/example.php');
        $script = $this->write('project/bin/check.php');
        $configFile = $this->write('project/config/app.php');
        $legacy = $this->write('project/config/legacy.php');
        $this->write('project/ambit.yaml', <<<'YAML'
php:
    paths: [src, tests]
    exclude: ['*/generated/*']
rector:
    extends: php
    exclude: [tests/Fixtures]
lint:
    extends: php
    paths: [bin, config]
    exclude: [config/legacy.php]
YAML
        );

        $directory = $this->root . '/project';
        $php = Scope::fromProject($directory);
        $rector = Scope::fromProject($directory, 'rector');
        $lint = Scope::fromProject($directory, 'lint');

        self::assertSame([$directory . '/src', $directory . '/tests'], $php->paths());
        self::assertSame($php->paths(), $rector->paths());
        self::assertSame([$directory . '/*/generated/*'], $php->exclusions());
        self::assertSame([
            $directory . '/*/generated/*',
            $directory . '/tests/Fixtures',
        ], $rector->exclusions());
        self::assertSame([
            $directory . '/src', $directory . '/tests', $directory . '/bin', $directory . '/config',
        ], $lint->paths());
        self::assertSame([
            $directory . '/*/generated/*', $directory . '/config/legacy.php',
        ], $lint->exclusions());

        $selector = new FileSelector();
        self::assertSame([$source, $test], $selector->findFiles($rector, ['php']));
        self::assertSame([$script, $configFile, $source, $fixture, $test], $selector->findFiles($lint, ['php']));
        self::assertTrue($lint->isExcluded($generated));
        self::assertTrue($lint->isExcluded($legacy));
        self::assertFalse($php->isExcluded($fixture));
    }

    public function testMultipleParentsAndChainedInheritancePreserveOrderAndDeduplicate(): void
    {
        $file = $this->write('ambit.yaml', <<<'YAML'
lint:
    extends: [php, tooling, php]
    paths: [src, config]
    exclude: [cache/, config/legacy.php]
php:
    extends: shared
    paths: [src]
    exclude: ['*/generated/*']
tooling:
    extends: shared
    paths: [bin]
    exclude: [bin/legacy.php]
shared:
    paths: [shared]
    exclude: [cache/]
YAML
        );

        $loader = new YamlConfigLoader();
        $config = $loader->load($file, 'lint');
        self::assertSame([
            $this->root . '/shared', $this->root . '/src', $this->root . '/bin', $this->root . '/config',
        ], $config->paths());
        self::assertSame([
            $this->root . '/cache/', $this->root . '/*/generated/*',
            $this->root . '/bin/legacy.php', $this->root . '/config/legacy.php',
        ], $config->exclusions());
        self::assertSame([$this->root . '/shared'], $loader->load($file, 'shared')->paths());
    }

    public function testEmptyChildListsKeepInheritedEntriesAndAbsolutePathsRemainAbsolute(): void
    {
        $absolute = $this->write('external.php');
        $file = $this->write('config/ambit.yaml',
            "php:\n    paths: ['" . $absolute . "', 'modules/*/src']\n"
            . "    exclude: ['" . $absolute . "']\n"
            . "lint:\n    extends: php\n    paths: []\n    exclude: []\n"
        );
        self::assertEquals(new Config(
            [$absolute, $this->root . '/config/modules/*/src'],
            [$absolute]
        ), (new YamlConfigLoader())->load($file, 'lint'));
    }

    public function testEmptyNamedSetsAreAllowed(): void
    {
        $file = $this->write('ambit.yaml', "php: {}\n");
        self::assertEquals(new Config([]), (new YamlConfigLoader())->load($file));
    }

    public function testPhpSetIsSelectedByDefaultRegardlessOfDeclarationOrder(): void
    {
        $this->write('src/example.php');
        $this->write('bin/check.php');
        $file = $this->write('ambit.yaml', "lint: {paths: [bin]}\nphp: {paths: [src]}\n");
        $loader = new YamlConfigLoader();
        self::assertSame([$this->root . '/src'], $loader->load($file)->paths());
        self::assertEquals($loader->load($file, 'php'), $loader->load($file));
        self::assertSame([$this->root . '/src'], Scope::fromProject($this->root)->paths());
    }

    public function testMissingPhpSetIsRejectedWithoutFallingBackToAnotherSet(): void
    {
        $this->write('ambit.yaml', "lint: {}\n");
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown scope set "php"');
        Scope::fromProject($this->root);
    }

    public function testExplicitSelectionDoesNotRequirePhpSet(): void
    {
        $file = $this->write('ambit.yaml', "lint: {}\n");
        self::assertEquals(new Config([]), (new YamlConfigLoader())->load($file, 'lint'));
        self::assertSame([], Scope::fromProject($this->root, 'lint')->paths());
    }

    public function testUnknownSelectedSetIsRejected(): void
    {
        $file = $this->write('ambit.yaml', "php: {}\n");
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown scope set "lint"');
        Scope::fromProject(dirname($file), 'lint');
    }

    /** @dataProvider invalidNamedConfigurations */
    public function testInvalidNamedConfigurationIsRejected(string $yaml, string $message): void
    {
        $file = $this->write('ambit.yaml', $yaml);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new YamlConfigLoader())->load($file);
    }

    public static function invalidNamedConfigurations(): array
    {
        return [
            'empty document' => ['', 'must be a mapping of named sets'],
            'scalar document' => ["src\n", 'must be a mapping of named sets'],
            'empty mapping' => ["{}\n", 'Unknown scope set "php"'],
            'list of sets' => ["[{}]\n", 'must map non-empty string names'],
            'empty name' => ["'': {}\n", 'must map non-empty string names'],
            'scalar definition' => ["php: src\n", 'must map non-empty string names'],
            'null definition' => ["php: null\n", 'must map non-empty string names'],
            'old single-scope format' => ["paths: []\nexclude: []\n", 'Unknown scope set "php"'],
            'old sets wrapper' => ["sets: {php: {}}\n", 'Unknown scope configuration keys: php'],
            'old default key' => ["default: php\nphp: {}\n", 'must map non-empty string names'],
            'unknown set key' => ["php: {excludes: []}\n", 'Unknown scope configuration keys: excludes'],
            'scalar paths' => ["php: {paths: src}\n", 'must be a list of paths'],
            'map paths' => ["php: {paths: {one: src}}\n", 'must be a list of paths'],
            'null paths' => ["php: {paths: null}\n", 'must be a list of paths'],
            'empty path' => ["php: {paths: ['']}\n", 'must be non-empty strings'],
            'non-string path' => ["php: {paths: [12]}\n", 'must be non-empty strings'],
            'scalar excludes' => ["php: {exclude: cache}\n", 'must be a list of paths'],
            'map excludes' => ["php: {exclude: {one: cache}}\n", 'must be a list of paths'],
            'null excludes' => ["php: {exclude: null}\n", 'must be a list of paths'],
            'empty exclusion' => ["php: {exclude: ['']}\n", 'must be non-empty strings'],
            'invalid parent type' => ["php: {extends: false}\n", 'extends must name a set'],
            'null parent' => ["php: {extends: null}\n", 'extends must name a set'],
            'map parents' => ["php: {extends: {one: other}}\n", 'extends must name a set'],
            'empty parent' => ["php: {extends: ''}\n", 'extends must contain non-empty set names'],
            'non-string parent' => ["php: {extends: [12]}\n", 'extends must contain non-empty set names'],
            'unknown parent' => ["php: {extends: missing}\n", 'Unknown parent scope set "missing" inherited by "php"'],
            'self cycle' => ["php: {extends: php}\n", 'Circular scope set inheritance: php -> php'],
            'indirect cycle' => ["php: {extends: lint}\nlint: {extends: php}\n", 'Circular scope set inheritance: php -> lint -> php'],
            'invalid unused set' => ["php: {}\nlint: {extends: missing}\n", 'Unknown parent scope set "missing" inherited by "lint"'],
        ];
    }

    private function write(string $path, string $contents = "<?php\n"): string
    {
        $path = $this->root . '/' . $path;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
        return $path;
    }
}
