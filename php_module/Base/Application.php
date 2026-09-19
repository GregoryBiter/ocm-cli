<?php

namespace Ocm\Base;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Основное приложение консоли OCM (Kernel).
 */
class Application extends SymfonyApplication {
    protected $services = [];
    protected $customCommands = [];

    const APP_NAME = 'OCM (OpenCart Module Manager)';
    const APP_VERSION = '2.0.0';

    public function __construct() {
        parent::__construct(self::APP_NAME, self::APP_VERSION);
        $this->bootstrapServices();
        $this->registerBuiltinCommands();
    }

    /**
     * Инициализация базовых сервисов.
     */
    protected function bootstrapServices() {
        $fileSystem = new \Ocm\Services\FileSystemService();
        $config = new \Ocm\Services\ConfigService();
        $openCart = new \Ocm\Services\OpenCartService();
        $module = new \Ocm\Services\ModuleService($fileSystem, $config, $openCart);
        $template = new \Ocm\Services\TemplateService($fileSystem);

        $database = new \Ocm\Services\DatabaseService();
        $agent = new \Ocm\Services\AgentSkillService($fileSystem, $openCart);
        $script = new \Ocm\Services\ScriptService($fileSystem);

        $this->services['filesystem'] = $fileSystem;
        $this->services['config'] = $config;
        $this->services['opencart'] = $openCart;
        $this->services['module'] = $module;
        $this->services['template'] = $template;
        $this->services['database'] = $database;
        $this->services['agent'] = $agent;
        $this->services['script'] = $script;
    }

    /**
     * Регистрация встроенных Artisan-команд.
     */
    protected function registerBuiltinCommands() {
        $this->add(new \Ocm\Commands\MakeModuleCommand());
        $this->add(new \Ocm\Commands\InitCommand());
        $this->add(new \Ocm\Commands\InstallCommand());
        $this->add(new \Ocm\Commands\DevCommand());
        $this->add(new \Ocm\Commands\BuildCommand());
        $this->add(new \Ocm\Commands\PublishCommand());
        $this->add(new \Ocm\Commands\UpdateCommand());
        $this->add(new \Ocm\Commands\UpdateCheckCommand());
        $this->add(new \Ocm\Commands\RemoveCommand());
        $this->add(new \Ocm\Commands\ReturnCommand());
        $this->add(new \Ocm\Commands\OcmodRefreshCommand());
        $this->add(new \Ocm\Commands\CacheClearCommand());
        $this->add(new \Ocm\Commands\LinkCommand());
        $this->add(new \Ocm\Commands\StatusCommand());
        $this->add(new \Ocm\Commands\TemplateListCommand());
        $this->add(new \Ocm\Commands\MigrateCommand());

        // База данных
        $this->add(new \Ocm\Commands\DbInfoCommand());
        $this->add(new \Ocm\Commands\DbQueryCommand());
        $this->add(new \Ocm\Commands\DbCliCommand());
        $this->add(new \Ocm\Commands\DbDumpCommand());
        $this->add(new \Ocm\Commands\DbImportCommand());

        // AI агенты и скилы
        $this->add(new \Ocm\Commands\AgentInstallCommand());
        $this->add(new \Ocm\Commands\AgentListCommand());

        // Дополнительные скрипты (scripts/)
        $this->add(new \Ocm\Commands\ScriptListCommand());
        $this->add(new \Ocm\Commands\ScriptRunCommand());
        $this->add(new \Ocm\Commands\LampCommand());
    }

    /**
     * Получить сервис по ключу.
     */
    public function getService($key) {
        return isset($this->services[$key]) ? $this->services[$key] : null;
    }

    /**
     * Зарегистрировать команду (совместимость с Command).
     */
    public function add(\Symfony\Component\Console\Command\Command $command): ?\Symfony\Component\Console\Command\Command {
        if ($command instanceof Command) {
            $command->setApplication($this);
            $this->customCommands[$command->getName()] = $command;
        }
        return parent::add($command);
    }

    /**
     * Запустить приложение. Поддерживает как $argv-массив, так и стандартный вызов.
     */
    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int {
        $rawArgv = null;
        $funcArgs = func_get_args();
        if (isset($funcArgs[0]) && is_array($funcArgs[0])) {
            $rawArgv = $funcArgs[0];
        } elseif (isset($_SERVER['argv'])) {
            $rawArgv = $_SERVER['argv'];
        }

        if ($rawArgv) {
            // 1. Проверка опции --script <path>
            $scriptOptionIndex = array_search('--script', $rawArgv);
            if ($scriptOptionIndex !== false && isset($rawArgv[$scriptOptionIndex + 1])) {
                $targetScript = $rawArgv[$scriptOptionIndex + 1];
                $resolved = $this->resolveExternalScriptPath($targetScript);
                if ($resolved) {
                    $args = array_slice($rawArgv, $scriptOptionIndex + 2);
                    return $this->executeExternalScript($resolved, $args);
                }
            }

            // 2. Проверка прямого запуска скрипта: ocm <script_name> [args...]
            $commandName = isset($rawArgv[1]) ? $rawArgv[1] : '';
            if ($commandName !== '' && strpos($commandName, '-') !== 0 && !$this->has($commandName)) {
                $scriptPath = $this->resolveExternalScriptPath($commandName);
                if ($scriptPath) {
                    $args = array_slice($rawArgv, 2);
                    return $this->executeExternalScript($scriptPath, $args);
                }
            }

            if (isset($funcArgs[0]) && is_array($funcArgs[0])) {
                $input = new ArgvInput($rawArgv);
                $output = $output ?: new ConsoleOutput();
            }
        }

        return parent::run($input, $output);
    }

    /**
     * Найти файл внешнего скрипта по имени команды (legacy).
     */
    public function resolveExternalScriptPath($script_name) {
        /** @var \Ocm\Services\ScriptService $scriptService */
        $scriptService = $this->getService('script');
        if ($scriptService) {
            $path = $scriptService->resolveScriptPath($script_name);
            if ($path) {
                return $path;
            }
        }

        $scriptsDir = defined('SCRIPT_DIR') ? SCRIPT_DIR . '/scripts/' : dirname(dirname(__DIR__)) . '/scripts/';
        if (!is_dir($scriptsDir)) {
            return null;
        }

        $candidates = [$scriptsDir . $script_name];
        if (pathinfo($script_name, PATHINFO_EXTENSION) === '') {
            $candidates[] = $scriptsDir . $script_name . '.php';
            $candidates[] = $scriptsDir . $script_name . '.sh';
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Запустить найденный внешний скрипт.
     */
    public function executeExternalScript($script_path, array $args = []) {
        /** @var \Ocm\Services\ScriptService $scriptService */
        $scriptService = $this->getService('script');
        if ($scriptService) {
            return $scriptService->execute($script_path, $args);
        }

        $extension = strtolower(pathinfo($script_path, PATHINFO_EXTENSION));
        $escapedArgs = array_map('escapeshellarg', $args);
        $argsStr = !empty($escapedArgs) ? ' ' . implode(' ', $escapedArgs) : '';

        if ($extension === 'php') {
            passthru('php ' . escapeshellarg($script_path) . $argsStr, $exit_code);
            return $exit_code;
        }

        if ($extension === 'sh') {
            passthru('bash ' . escapeshellarg($script_path) . $argsStr, $exit_code);
            return $exit_code;
        }

        passthru(escapeshellarg($script_path) . $argsStr, $exit_code);
        return $exit_code;
    }

    /**
     * Вывод общей справки (legacy поддержка).
     */
    public function showHelp($output) {
        if ($output instanceof Output) {
            $output->alert("==================================================");
            $output->alert("  " . self::APP_NAME . " - v" . self::APP_VERSION);
            $output->alert("==================================================");
            $output->writeln("Использование: ocm <команда> [аргументы] [опции]");
            $output->writeln();
            $output->comment("Доступные команды:");

            foreach ($this->all() as $name => $command) {
                if (!$command->isHidden()) {
                    $output->writeln("  " . str_pad($name, 20) . " " . $command->getDescription());
                }
            }
            $output->writeln();
        }
    }

    public function getCommands() {
        return $this->customCommands;
    }
}
