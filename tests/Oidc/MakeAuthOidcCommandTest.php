<?php

namespace Quellabs\CanvasAuthorization\Tests\Oidc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quellabs\CanvasAuthorization\MakeAuthOidcCommand;
use Quellabs\CanvasAuthorization\ServiceProvider;
use Quellabs\Contracts\IO\ConsoleInput;
use Quellabs\Contracts\IO\ConsoleOutput;
use Quellabs\Sculpt\ConfigurationManager;
use Quellabs\Support\ComposerUtils;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use RuntimeException;

class MakeAuthOidcCommandTest extends TestCase {

    private string $projectRoot = '';
    private string $previousDirectory = '';
    private mixed $previousProjectRootCache = null;

    /**
     * Create an isolated application root for the real installer command.
     * @return void
     */
    protected function setUp(): void {
        $this->previousDirectory = getcwd() ?: throw new RuntimeException('Could not read the current directory.');
        $this->projectRoot = sys_get_temp_dir() . '/canvas-auth-oidc-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/config', 0755, true);
        file_put_contents($this->projectRoot . '/composer.json', '{"name":"test/oidc-app","autoload":{"psr-4":{"App\\\\":"src/"}}}');

        $cache = new ReflectionProperty(ComposerUtils::class, 'projectRootPathCache');
        $this->previousProjectRootCache = $cache->getValue();
        $cache->setValue(null, null);
        chdir($this->projectRoot);
    }

    /**
     * Restore the original process state and remove generated test files.
     * @return void
     */
    protected function tearDown(): void {
        if ($this->previousDirectory !== '') {
            chdir($this->previousDirectory);
        }

        (new ReflectionProperty(ComposerUtils::class, 'projectRootPathCache'))->setValue(null, $this->previousProjectRootCache);

        $tempRoot = realpath(sys_get_temp_dir());
        $projectRoot = realpath($this->projectRoot);

        if ($projectRoot === false || $tempRoot === false || !str_starts_with($projectRoot, $tempRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing to remove a test path outside the temporary directory.');
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($projectRoot);
    }

    /**
     * Cover both modes with every template engine advertised by the package.
     * @return array<string, array{string, string, string}>
     */
    public static function installCases(): array {
        $cases = [];

        foreach (['smarty' => 'tpl', 'twig' => 'twig', 'blade' => 'blade.php', 'latte' => 'latte', 'php' => 'php'] as $engine => $extension) {
            foreach (['password_and_oidc', 'oidc_only'] as $mode) {
                $cases["{$engine} {$mode}"] = [$engine, $extension, $mode];
            }
        }

        return $cases;
    }

    /**
     * Generated files have the chosen login mode and resolvable view names.
     * @param string $engine
     * @param string $extension
     * @param string $mode
     * @return void
     */
    #[DataProvider('installCases')]
    public function testInstallsWorkingFilesForEveryModeAndTemplateEngine(string $engine, string $extension, string $mode): void {
        file_put_contents($this->projectRoot . '/config/app.php', '<?php return ' . var_export(['template_engine' => $engine], true) . ';');
        $input = $this->createMock(ConsoleInput::class);
        $output = $this->createMock(ConsoleOutput::class);
        $command = new MakeAuthOidcCommand($input, $output, new ServiceProvider());

        $this->assertSame(0, $command->execute(new ConfigurationManager(["--mode={$mode}"])));

        $oidcConfig = require $this->projectRoot . '/config/oidc.php';
        $controller = file_get_contents($this->projectRoot . '/src/Controllers/OidcAuthenticationController.php');

        $this->assertSame($mode, $oidcConfig['login_mode']);
        $this->assertIsString($controller);
        $this->assertStringNotContainsString('{{ template_ext }}', $controller);
        $this->assertStringContainsString("oidc_complete.{$extension}", $controller);
        $this->assertFileExists($this->projectRoot . "/templates/oidc_complete.{$extension}");
        $this->assertFileExists($this->projectRoot . "/templates/oidc_error.{$extension}");

        if ($mode === 'oidc_only') {
            $this->assertFileExists($this->projectRoot . '/src/Entities/UserEntity.php');
            $this->assertFileExists($this->projectRoot . '/src/Exceptions/UserCreationException.php');
            $this->assertFileExists($this->projectRoot . "/templates/oidc_only_login.{$extension}");
            $this->assertStringNotContainsString('{{ template_ext }}', file_get_contents($this->projectRoot . '/src/Controllers/OidcOnlyLoginController.php'));
            $this->assertFileDoesNotExist($this->projectRoot . '/src/Controllers/AuthenticationController.php');
        } else {
            $this->assertFileDoesNotExist($this->projectRoot . '/src/Controllers/OidcOnlyLoginController.php');
        }
    }

    /**
     * Re-running the installer must preserve edited application files when overwrite is declined.
     * @return void
     */
    public function testInstallerPreservesAnExistingController(): void {
        file_put_contents($this->projectRoot . '/config/app.php', '<?php return ' . var_export(['template_engine' => 'php'], true) . ';');
        $controllerPath = $this->projectRoot . '/src/Controllers/OidcAuthenticationController.php';
        mkdir(dirname($controllerPath), 0755, true);
        file_put_contents($controllerPath, '<?php // application customization');

        $input = $this->createMock(ConsoleInput::class);
        $input->method('confirm')->willReturn(false);
        $command = new MakeAuthOidcCommand($input, $this->createMock(ConsoleOutput::class), new ServiceProvider());

        $this->assertSame(0, $command->execute(new ConfigurationManager()));
        $this->assertSame('<?php // application customization', file_get_contents($controllerPath));
    }
}
