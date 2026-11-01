<?php

declare(strict_types=1);

namespace Ambit\Tests\Unit;

use InvalidArgumentException;
use Ambit\Config;
use Ambit\FileSelector;
use Ambit\PathMatcher;
use Ambit\Scope;
use Ambit\YamlConfigLoader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Exception\ParseException;

class ScopeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ambit-scope-' . bin2hex(random_bytes(8));
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

    public function testYamlPathsAreRelativeToConfigurationAndRetainPatternsForRector(): void
    {
        $this->write('config/src/example.php');
        $absoluteFile = $this->write('external.php');
        $config = $this->write('config/ambit.yaml',
            "paths:\n    - src\n    - 'modules/*/src'\n    - " . $absoluteFile
            . "\nexclude:\n    - '*/generated/*'\n    - missing/cache/\n"
        );
        self::assertEquals(new Config(
            [$this->root . '/config/src', $this->root . '/config/modules/*/src', $absoluteFile],
            [$this->root . '/config/*/generated/*', $this->root . '/config/missing/cache/']
        ), (new YamlConfigLoader())->load($config));
        $scope = Scope::fromProject(dirname($config));
        self::assertSame([
            $this->root . '/config/src',
            $this->root . '/config/modules/*/src',
            $absoluteFile,
        ], $scope->paths());
        self::assertSame([
            $this->root . '/config/*/generated/*',
            $this->root . '/config/missing/cache/',
        ], $scope->exclusions());
    }

    public function testInclusionsExpandLikeRectorAndDeduplicateFiles(): void
    {
        $first = $this->write('modules/one/src/first.php');
        $second = $this->write('modules/two/src/second.php');
        $this->write('modules/one/tests/outside.php');
        $script = $this->write('script.php');
        $scope = new Scope($this->config(['modules/*/src', 'script.php', 'missing/*', 'modules/one/src/first.php']));
        self::assertSame([
            $this->root . '/modules/one/src',
            $this->root . '/modules/two/src',
            $script,
            $first,
        ], $scope->resolvePaths());
        self::assertSame([$first, $second, $script], (new FileSelector())->findFiles($scope, ['php']));
    }

    public function testDoubleStarIsAGlobComponentRatherThanRecursiveGlobstar(): void
    {
        $direct = $this->write('modules/one/src/direct.php');
        $this->write('modules/one/nested/src/deeper.php');
        $scope = new Scope($this->config(['modules/**/src']));
        self::assertSame([$direct], (new FileSelector())->findFiles($scope));
    }

    public function testFileDiscoveryAndCandidateFilteringUseTheSameScope(): void
    {
        $included = $this->write('src/included.php');
        $sibling = $this->write('src/cache-helper.php');
        $upperCase = $this->write('src/UPPER.PHP');
        $text = $this->write('src/readme.txt');
        $excluded = $this->write('src/cache/invalid.php');
        $generated = $this->write('src/feature/generated/invalid.php');
        $single = $this->write('src/skip.php');
        $outside = $this->write('src-other/outside.php');
        $scope = new Scope($this->config(['src'], ['src/cache/', '*/generated/*', 'src/skip.php', 'missing/']));
        $selector = new FileSelector();
        $expected = [$upperCase, $sibling, $included];
        self::assertSame($expected, $selector->findFiles($scope, ['php']));
        self::assertSame($expected, $selector->filterFiles($scope, [
            $included, $included, $sibling, $upperCase, $text, $excluded, $generated, $single, $outside,
            $this->root . '/src/deleted.php',
        ], ['php']));
        self::assertContains($text, $selector->findFiles($scope));
    }

    public function testSymlinkRootsAndExclusionsResolveToTheSameFiles(): void
    {
        $file = $this->write('src/file.php');
        symlink($this->root . '/src', $this->root . '/alias');
        $scope = new Scope($this->config(['alias', 'src']));
        $selector = new FileSelector();
        self::assertSame([$file], $selector->findFiles($scope));
        self::assertSame([$file], $selector->filterFiles($scope, [$this->root . '/alias/file.php']));
        self::assertSame([], $selector->findFiles(new Scope($this->config(['src'], ['alias/']))));
    }

    public function testRectorExclusionPatternsSupportPrefixSuffixAndFnmatch(): void
    {
        $matcher = new PathMatcher();
        self::assertTrue($matcher->matches('/project/src/file.php', '/project/src/'));
        self::assertTrue($matcher->matches('/project/src-old/file.php', '/project/src'));
        self::assertFalse($matcher->matches('/project/src-old/file.php', '/project/src/'));
        self::assertTrue($matcher->matches('/project/src/old.php', 'old.php'));
        self::assertTrue($matcher->matches('/project/src/nested/old.php', 'src/*/old.php'));
        self::assertTrue($matcher->matches('/project/src/one.php', '*/src/o?e.[p]hp'));
        self::assertTrue($matcher->matches('/project/src/generated/file.php', '*/generated/*'));
        self::assertTrue($matcher->matches('C:\\project\\src\\file.php', 'C:/project/src/'));
        self::assertFalse($matcher->matches('/project/src/file.php', ''));
    }

    public function testRectorResolvesExistingParentSegmentsInExclusions(): void
    {
        $file = $this->write('src/file.php');
        $this->write('other/placeholder');
        $scope = new Scope($this->config(['src'], ['other/../src']));
        self::assertTrue($scope->isExcluded($file));
    }

    public function testMissingLiteralInclusionsFail(): void
    {
        $config = $this->write('ambit.yaml', "paths: [missing.php]\n");
        self::assertEquals(
            $this->config(['missing.php']),
            (new YamlConfigLoader())->load($config)
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configured source path does not exist');
        Scope::fromProject($this->root);
    }

    public function testQuestionMarkAloneIsNotAnInclusionGlobInRector(): void
    {
        $this->write('src/a.php');
        $this->expectException(InvalidArgumentException::class);
        new Scope($this->config(['src/?.php']));
    }

    public function testUnmatchedWildcardsAndEmptyScopesSelectNoFiles(): void
    {
        $selector = new FileSelector();
        self::assertSame([], $selector->findFiles(new Scope($this->config(['missing/*']))));
        self::assertSame([], $selector->findFiles(new Scope($this->config([]))));
    }

    /** @dataProvider invalidConfigurations */
    public function testInvalidConfigurationIsRejected(string $yaml): void
    {
        $config = $this->write('ambit.yaml', $yaml);
        $this->expectException(InvalidArgumentException::class);
        (new YamlConfigLoader())->load($config);
    }

    public static function invalidConfigurations(): array
    {
        return [
            'empty document' => [''],
            'missing paths' => ["exclude: []\n"],
            'scalar paths' => ["paths: src\n"],
            'scalar excludes' => ["paths: []\nexclude: src\n"],
            'null excludes' => ["paths: []\nexclude: null\n"],
            'unknown key' => ["paths: []\nexcludes: []\n"],
            'map instead of list' => ["paths: {one: src}\n"],
            'non-string inclusion' => ["paths: [12]\n"],
            'empty exclusion' => ["paths: []\nexclude: ['']\n"],
        ];
    }

    public function testMalformedYamlIsRejected(): void
    {
        $config = $this->write('ambit.yaml', 'paths: [');
        $this->expectException(ParseException::class);
        Scope::fromProject(dirname($config));
    }

    private function config(array $paths, array $exclusions = []): Config
    {
        $absolute = function (string $path): string {
            return $this->root . '/' . $path;
        };
        return new Config(array_map($absolute, $paths), array_map($absolute, $exclusions));
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
