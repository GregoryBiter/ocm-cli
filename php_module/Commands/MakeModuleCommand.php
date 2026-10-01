<?php

namespace Ocm\Commands;

use Ocm\Base\Command;
use Ocm\Base\Input;
use Ocm\Base\Output;
use Ocm\Services\ConfigService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Artisan-style команда создания нового модуля из шаблона.
 */
class MakeModuleCommand extends Command {
    protected $name = 'make:module';
    protected $description = 'Создать новый модуль OpenCart из шаблона';

    protected function configure() {
        $this->setName('make:module')
             ->setAliases(['create'])
             ->setDescription('Создать новый модуль OpenCart из шаблона')
             ->addArgument('name', InputArgument::OPTIONAL, 'Имя модуля (snake_case, например my_module)')
             ->addOption('template', 't', InputOption::VALUE_OPTIONAL, 'Название шаблона')
             ->addOption('title', null, InputOption::VALUE_OPTIONAL, 'Отображаемое название модуля')
             ->addOption('author', null, InputOption::VALUE_OPTIONAL, 'Автор модуля')
             ->addOption('ver', null, InputOption::VALUE_OPTIONAL, 'Версия модуля', '1.0.0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('OCM: Создание нового модуля');

        $templateService = $this->getService('template');
        $configService = $this->getService('config');

        // Получение доступных шаблонов
        $availableTemplates = $templateService->getAvailableTemplates();
        if (empty($availableTemplates)) {
            $io->error('Шаблоны не найдены! Проверьте папку templates.');
            return self::FAILURE;
        }

        // Выбор шаблона
        $templateName = $input->getOption('template');
        if (!$templateName) {
            $templateNames = array_keys($availableTemplates);
            $templateLabels = [];
            foreach ($availableTemplates as $name => $info) {
                $templateLabels[] = sprintf('%s [%s]', $name, $info['type']);
            }
            $selectedLabel = $io->choice('Выберите шаблон модуля:', $templateLabels, $templateLabels[0]);
            // Извлекаем чистое имя
            $templateName = explode(' [', $selectedLabel)[0];
        }

        $templatePath = $templateService->getTemplatePath($templateName);
        if (!$templatePath) {
            $io->error("Шаблон '{$templateName}' не существует.");
            return self::FAILURE;
        }

        // Имя модуля
        $moduleName = $input->getArgument('name');
        if (!$moduleName) {
            $moduleName = $io->ask('Введите имя модуля (в формате snake_case, например: super_seo)', 'my_module', function ($answer) {
                if (empty(trim($answer))) {
                    throw new \RuntimeException('Имя модуля не может быть пустым.');
                }
                return preg_replace('/[^a-z0-9_]+/i', '_', strtolower(trim($answer)));
            });
        }

        $targetDir = getcwd() . '/' . $moduleName;
        if (is_dir($targetDir)) {
            $io->error("Директория '{$moduleName}' уже существует!");
            return self::FAILURE;
        }

        $camelCaseName = $configService->toCamelCase($moduleName);
        $camelCaseLowerName = $configService->toCamelCaseLower($moduleName);
        $moduleTitle = $input->getOption('title') ?: ucwords(str_replace('_', ' ', $moduleName));
        $author = $input->getOption('author') ?: 'Developer';
        $version = $input->getOption('ver') ?: '1.0.0';

        $placeholders = [
            '{{#ModuleName}}' => $camelCaseName,
            '{{#moduleName}}' => $camelCaseLowerName,
            '{{#module_name}}' => $moduleName,
            '{{#NameModule}}' => $camelCaseName,
            '{{#module_title}}' => $moduleTitle,
            '{{#author}}' => $author,
            '{{#version}}' => $version,
            '{{#year}}' => date('Y'),
            '{{#date}}' => date('Y-m-d')
        ];

        // Копирование шаблона
        $io->text("Генерация структуры из шаблона <info>{$templateName}</info>...");
        $success = $templateService->createFromTemplate($templateName, $targetDir, $placeholders);

        if (!$success) {
            $io->error("Не удалось скопировать файлы шаблона '{$templateName}'.");
            return self::FAILURE;
        }

        // Создаем или обновляем opencart-module.json внутри нового модуля
        $jsonFile = $targetDir . '/opencart-module.json';
        $metadata = [
            'name' => $moduleTitle,
            'code' => $moduleName,
            'version' => $version,
            'author' => $author,
            'description' => 'OpenCart module ' . $moduleTitle
        ];
        file_put_contents($jsonFile, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Инициализация .ocm/files.json
        $fileSystemService = $this->getService('filesystem');
        $moduleUploadDir = $targetDir . '/upload';
        if (is_dir($moduleUploadDir)) {
            $files = $fileSystemService->findAllFiles($moduleUploadDir, $moduleUploadDir);
            $moduleConfig = new ConfigService($targetDir);
            $moduleConfig->saveFilesList($files);
        }

        $io->success([
            "Модуль '{$moduleName}' успешно создан!",
            "Путь: {$targetDir}",
            "Перейдите в директорию: cd {$moduleName}",
            "И запустите режим разработки: ocm dev"
        ]);

        return self::SUCCESS;
    }

    public function handle(Input $input, Output $output) {
        $createCmd = new CreateCommand();
        $createCmd->setApplication($this->app);
        $createCmd->handle($input, $output);
    }
}
