<?php

namespace Ocm\Commands;

use Ocm\Base\Command;
use Ocm\Base\Input;
use Ocm\Base\Output;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда сборки модуля OpenCart (module:build / build).
 */
class BuildCommand extends Command {
    protected $name = 'module:build';
    protected $description = 'Сборка дистрибутивного архива модуля (*.ocmod.zip)';

    protected function configure() {
        $this->setName('module:build')
             ->setAliases(['build'])
             ->setDescription('Сборка готового к установке архива модуля (*.ocmod.zip)')
             ->addOption('archive', 'a', InputOption::VALUE_NONE, 'Создать ZIP-архив (по умолчанию включено)')
             ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Путь для сохранения архива');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('OCM: Сборка модуля OpenCart');

        $config = $this->getService('config');
        $fileSystem = $this->getService('filesystem');

        $currentDir = $config->getCurrentDir();
        $metadata = $config->loadModuleMetadata();
        $moduleCode = !empty($metadata['code']) ? $metadata['code'] : basename($currentDir);
        $version = !empty($metadata['version']) ? $metadata['version'] : '1.0.0';

        $outputFilename = $input->getOption('output') ?: "{$moduleCode}.ocmod.zip";
        $archivePath = (strpos($outputFilename, '/') === 0) ? $outputFilename : ($currentDir . '/' . $outputFilename);

        $moduleUploadDir = $config->getModuleDir();
        $hasUploadDir = is_dir($moduleUploadDir);
        $buildFile = $currentDir . '/.build-module';

        if (!$hasUploadDir && !file_exists($buildFile)) {
            $io->error([
                "Не найдена директория 'upload' и отсутствует файл '.build-module'.",
                "Нечего собирать в текущей папке: {$currentDir}"
            ]);
            return self::FAILURE;
        }

        if (!$fileSystem->checkZipExtension()) {
            $io->error('PHP расширение ZIP не установлено! Установите php-zip.');
            return self::FAILURE;
        }

        if (file_exists($archivePath)) {
            unlink($archivePath);
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $io->error("Не удалось создать архив: {$archivePath}");
            return self::FAILURE;
        }

        $packedFilesCount = 0;

        // Вариант 1: Стандартный модуль OpenCart с папкой upload/
        if ($hasUploadDir) {
            $io->text("Упаковка файлов из <info>upload/</info>...");
            $uploadFiles = $fileSystem->findAllFiles($moduleUploadDir, $moduleUploadDir);

            foreach ($uploadFiles as $relPath) {
                $fullPath = $moduleUploadDir . '/' . $relPath;
                $zip->addFile($fullPath, 'upload/' . $relPath);
                $packedFilesCount++;
            }

            // Добавляем модификатор (install.xml или index.xml), упаковывая как install.xml
            $ocmodFile = $config->getOcmodFilePath();
            if ($ocmodFile && file_exists($ocmodFile)) {
                $zip->addFile($ocmodFile, 'install.xml');
                $io->text("  + <info>" . basename($ocmodFile) . "</info> (упакован как install.xml)");
                $packedFilesCount++;
            }

            // Добавляем install.php, если он есть
            $installPhp = $currentDir . '/install.php';
            if (file_exists($installPhp)) {
                $zip->addFile($installPhp, 'install.php');
                $io->text("  + <info>install.php</info> (скрипт установки)");
                $packedFilesCount++;
            }

            // Добавляем install.json, если есть
            $installJson = $currentDir . '/install.json';
            if (file_exists($installJson)) {
                $zip->addFile($installJson, 'install.json');
                $io->text("  + <info>install.json</info>");
                $packedFilesCount++;
            }

            // Добавляем opencart-module.json, если есть
            $moduleJson = $currentDir . '/opencart-module.json';
            if (file_exists($moduleJson)) {
                $zip->addFile($moduleJson, 'opencart-module.json');
                $io->text("  + <info>opencart-module.json</info> (метаданные модуля)");
                $packedFilesCount++;
            }
        } else {
            // Вариант 2: Легаси сборка по шаблонам .build-module
            $io->text("Сборка по шаблонам из <info>.build-module</info>...");
            $patterns = file($buildFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($patterns as $pattern) {
                $trimmed = trim($pattern);
                if ($trimmed === '' || strpos($trimmed, '#') === 0) continue;

                $matchedFiles = $fileSystem->findFilesByPattern($trimmed, $currentDir);
                foreach ($matchedFiles as $file) {
                    $fullPath = $currentDir . '/' . $file;
                    $zip->addFile($fullPath, 'upload/' . $file);
                    $packedFilesCount++;
                }
            }
        }

        $zip->close();

        if ($packedFilesCount === 0) {
            @unlink($archivePath);
            $io->warning("Не найдено файлов для включения в архив.");
            return self::FAILURE;
        }

        $fileSizeKb = round(filesize($archivePath) / 1024, 2);
        $io->success([
            "Сборка модуля успешно завершена!",
            "Архив: " . basename($archivePath) . " ({$fileSizeKb} KB)",
            "Путь: {$archivePath}",
            "Включено файлов: {$packedFilesCount}"
        ]);

        return self::SUCCESS;
    }

    /**
     * Обратная совместимость с легаси вызовами handle()
     */
    public function handle(Input $input, Output $output) {
        $fileSystem = $this->app->getService('filesystem');
        $config = $this->app->getService('config');
        $archive = $input->hasOption('a') || $input->hasOption('archive');

        $buildFile = getcwd() . '/.build-module';
        $moduleDir = $config->getModuleDir();

        if (is_dir($moduleDir)) {
            $metadata = $config->loadModuleMetadata();
            $moduleCode = isset($metadata['code']) ? $metadata['code'] : basename(getcwd());
            $archivePath = getcwd() . "/{$moduleCode}.ocmod.zip";

            if (file_exists($archivePath)) unlink($archivePath);

            $zip = new \ZipArchive();
            if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === TRUE) {
                $uploadFiles = $fileSystem->findAllFiles($moduleDir, $moduleDir);
                foreach ($uploadFiles as $file) {
                    $zip->addFile($moduleDir . '/' . $file, 'upload/' . $file);
                }
                $ocmodPath = $config->getOcmodFilePath();
                if ($ocmodPath && file_exists($ocmodPath)) {
                    $zip->addFile($ocmodPath, 'install.xml');
                }
                $zip->close();
                $output->success("Создан архив: " . basename($archivePath));
            }
            return;
        }

        if (file_exists($buildFile)) {
            $buildDir = getcwd() . '/build-module/upload';
            if (!is_dir($buildDir)) mkdir($buildDir, 0777, true);
            else $fileSystem->cleanDirectory($buildDir);

            $patterns = file($buildFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $copiedCount = 0;

            foreach ($patterns as $pattern) {
                if (strpos(trim($pattern), '#') === 0 || empty(trim($pattern))) continue;
                $matchedFiles = $fileSystem->findFilesByPattern(trim($pattern));
                foreach ($matchedFiles as $file) {
                    $destPath = $buildDir . '/' . $file;
                    if (!is_dir(dirname($destPath))) mkdir(dirname($destPath), 0777, true);
                    if (copy(getcwd() . '/' . $file, $destPath)) {
                        $copiedCount++;
                        $output->writeln("  Копирование: {$file}");
                    }
                }
            }

            $output->success("Сборка завершена. Скопировано файлов: {$copiedCount}");
            if ($archive && $fileSystem->checkZipExtension()) {
                $moduleName = basename(getcwd());
                $archivePath = getcwd() . "/build-module/{$moduleName}.ocmod.zip";
                $zip = new \ZipArchive();
                if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === TRUE) {
                    $fileSystem->addDirToZip($zip, $buildDir, 'upload');
                    $zip->close();
                    $output->success("Создан архив: " . basename($archivePath));
                }
            }
        } else {
            $output->error("Файл .build-module не найден.");
        }
    }
}
