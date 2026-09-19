<?php

namespace Ocm\Commands;

use Ocm\Base\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда публикации модуля на сервер обновлений (module:publish / publish / release).
 */
class PublishCommand extends Command {
    protected $name = 'module:publish';
    protected $description = 'Публикация собранного модуля на сервер обновлений';

    protected function configure() {
        $this->setName('module:publish')
             ->setAliases(['publish', 'release'])
             ->setDescription('Публикация модуля на сервер обновлений OCM')
             ->addOption('server', 's', InputOption::VALUE_OPTIONAL, 'URL сервера обновлений', getenv('UPDATE_SERVER_URL') ?: 'http://localhost:8000')
             ->addOption('key', 'k', InputOption::VALUE_OPTIONAL, 'API ключ сервера', getenv('UPDATE_SERVER_API_KEY') ?: '')
             ->addOption('file', 'f', InputOption::VALUE_OPTIONAL, 'Путь к ZIP архиву модуля')
             ->addOption('build', 'b', InputOption::VALUE_NONE, 'Собрать архив заново перед отправкой');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('OCM: Публикация модуля на сервер обновлений');

        $config = $this->getService('config');
        $currentDir = $config->getCurrentDir();
        $metadata = $config->loadModuleMetadata();

        $code = !empty($metadata['code']) ? $metadata['code'] : basename($currentDir);
        $version = !empty($metadata['version']) ? $metadata['version'] : '1.0.0';
        $serverUrl = rtrim((string)$input->getOption('server'), '/');
        $apiKey = (string)$input->getOption('key');

        if (empty($serverUrl)) {
            $io->error('Не указан URL сервера обновлений (--server или переменная UPDATE_SERVER_URL).');
            return self::FAILURE;
        }

        $archivePath = $input->getOption('file');
        if (empty($archivePath) || $input->getOption('build')) {
            $defaultArchive = "{$currentDir}/{$code}.ocmod.zip";
            if (!file_exists($defaultArchive) || $input->getOption('build')) {
                $io->text("Сборка архива модуля...");
                $buildCommand = $this->getApplication()->find('module:build');
                $exitCode = $buildCommand->run($input, $output);
                if ($exitCode !== 0) {
                    $io->error('Ошибка при сборке архива модуля.');
                    return self::FAILURE;
                }
            }
            $archivePath = $defaultArchive;
        }

        if (!file_exists($archivePath)) {
            $io->error("Файл архива не найден: {$archivePath}");
            return self::FAILURE;
        }

        $io->section("Отправка на сервер: {$serverUrl}");
        $io->table(
            ['Параметр', 'Значение'],
            [
                ['Модуль', $code],
                ['Версия', $version],
                ['Файл', basename($archivePath) . ' (' . round(filesize($archivePath) / 1024, 2) . ' KB)'],
                ['Сервер', $serverUrl],
                ['API Ключ', !empty($apiKey) ? substr($apiKey, 0, 4) . '...' . substr($apiKey, -4) : '<не указан>'],
            ]
        );

        $uploadUrl = "{$serverUrl}/api/v1/modules/upload";
        $ch = curl_init();

        $postData = [
            'module_file' => new \CURLFile($archivePath, 'application/zip', basename($archivePath)),
            'code' => $code,
            'version' => $version,
            'name' => $metadata['title'] ?? $metadata['name'] ?? $code,
            'description' => $metadata['description'] ?? '',
            'author' => $metadata['author'] ?? '',
            'author_url' => $metadata['author_url'] ?? $metadata['link'] ?? '',
            'category' => $metadata['category'] ?? 'module',
        ];

        $headers = [
            'Accept: application/json',
            'User-Agent: OCM-CLI/' . \Ocm\Base\Application::APP_VERSION,
        ];
        if (!empty($apiKey)) {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $io->error("Ошибка cURL: {$curlError}");
            return self::FAILURE;
        }

        $json = json_decode((string)$response, true);

        if ($httpCode >= 200 && $httpCode < 300 && !empty($json['success'])) {
            $data = $json['data'] ?? [];
            $io->success([
                "Модуль '{$code}' v{$version} успешно опубликован на сервере!",
                "Сервер: {$serverUrl}",
            ]);
            return self::SUCCESS;
        }

        $errorMessage = $json['message'] ?? ($json['error'] ?? "HTTP ошибка {$httpCode}: {$response}");
        $io->error("Не удалось опубликовать модуль: {$errorMessage}");
        return self::FAILURE;
    }
}
