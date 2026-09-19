<?php

namespace Ocm\Commands;

use Ocm\Base\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда обновления модуля OpenCart (module:update / update).
 */
class UpdateCommand extends Command {
    protected $name = 'module:update';
    protected $description = 'Обновление модуля OpenCart с сервера обновлений';

    protected function configure() {
        $this->setName('module:update')
             ->setAliases(['update'])
             ->setDescription('Обновить модуль OpenCart до последней версии с сервера обновлений')
             ->addArgument('module', InputArgument::OPTIONAL, 'Код модуля для обновления')
             ->addOption('server', 's', InputOption::VALUE_OPTIONAL, 'URL сервера обновлений', getenv('UPDATE_SERVER_URL') ?: 'http://localhost:8000')
             ->addOption('key', 'k', InputOption::VALUE_OPTIONAL, 'API ключ', getenv('UPDATE_SERVER_API_KEY') ?: '')
             ->addOption('target', 't', InputOption::VALUE_OPTIONAL, 'Путь к директории OpenCart')
             ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Автоматически подтверждать обновление');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('OCM: Обновление модуля OpenCart');

        $config = $this->getService('config');
        $fileSystem = $this->getService('filesystem');
        $openCart = $this->getService('opencart');

        $serverUrl = rtrim((string)$input->getOption('server'), '/');
        $apiKey = (string)$input->getOption('key');

        $targetDir = $input->getOption('target') ?: $openCart->getOpenCartPath();
        if (!$targetDir || !is_dir($targetDir)) {
            $io->error([
                "Не найден привязанный каталог OpenCart.",
                "Выполните 'ocm link /path/to/opencart' или укажите опцию --target.",
            ]);
            return self::FAILURE;
        }

        $currentDir = $config->getCurrentDir();
        $metadata = $config->loadModuleMetadata();

        $code = $input->getArgument('module') ?: (!empty($metadata['code']) ? $metadata['code'] : basename($currentDir));
        $currentVersion = !empty($metadata['version']) ? $metadata['version'] : '0.0.0';

        $io->text("Проверка обновлений для модуля: <info>{$code}</info> (текущая версия: {$currentVersion})...");

        // Проверка через сервер API
        $checkUrl = "{$serverUrl}/api/v1/modules/check";
        $ch = curl_init();

        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: OCM-CLI/' . \Ocm\Base\Application::APP_VERSION,
        ];
        if (!empty($apiKey)) {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $checkUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'version' => $currentVersion,
            ]),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $io->error("Ошибка связи с сервером обновлений: {$curlError}");
            return self::FAILURE;
        }

        $json = json_decode((string)$response, true);
        if ($httpCode !== 200 || empty($json['success'])) {
            $errorMsg = $json['message'] ?? ($json['error'] ?? "HTTP {$httpCode}: {$response}");
            $io->error("Сервер вернул ошибку: {$errorMsg}");
            return self::FAILURE;
        }

        $result = $json['result'] ?? [];
        if (!isset($result['status']) || $result['status'] !== 'update_available') {
            $io->success("Модуль '{$code}' уже обновлен до актуальной версии ({$currentVersion}).");
            return self::SUCCESS;
        }

        $newVersion = $result['version'];
        $downloadUrl = $result['download_url'] ?? "{$serverUrl}/api/v1/modules/{$code}/download";

        $io->note("Найдена новая версия: {$newVersion} (текущая: {$currentVersion})");

        if (!$input->getOption('yes') && !$io->confirm("Установить обновление v{$newVersion} в '{$targetDir}'?", true)) {
            $io->warning("Обновление отменено пользователем.");
            return self::SUCCESS;
        }

        // Скачивание архива
        $io->text("Скачивание пакета обновления...");
        $tempZip = tempnam(sys_get_temp_dir(), 'ocm_upd_') . '.zip';

        $fp = fopen($tempZip, 'w+');
        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        $dlCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $dlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($dlError || $dlCode !== 200) {
            @unlink($tempZip);
            $io->error("Ошибка скачивания архива обновления (HTTP {$dlCode}): {$dlError}");
            return self::FAILURE;
        }

        // Распаковка и установка
        $io->text("Установка файлов в OpenCart...");
        $zip = new \ZipArchive();
        if ($zip->open($tempZip) !== true) {
            @unlink($tempZip);
            $io->error("Не удалось открыть загруженный ZIP архив.");
            return self::FAILURE;
        }

        $extractDir = sys_get_temp_dir() . '/ocm_ext_' . uniqid();
        mkdir($extractDir, 0777, true);
        $zip->extractTo($extractDir);
        $zip->close();
        @unlink($tempZip);

        $uploadSource = $extractDir . '/upload';
        $installedFiles = 0;

        if (is_dir($uploadSource)) {
            $files = $fileSystem->findAllFiles($uploadSource, $uploadSource);
            foreach ($files as $rel) {
                $src = $uploadSource . '/' . $rel;
                $dst = $targetDir . '/' . $rel;
                $dstDir = dirname($dst);
                if (!is_dir($dstDir)) {
                    @mkdir($dstDir, 0777, true);
                }
                if (@copy($src, $dst)) {
                    $installedFiles++;
                }
            }
        }

        // Очистка временной папки
        $fileSystem->removeDirectory($extractDir);

        // Обновление модификаторов через OCM CLI
        $io->text("Обновление кэша модификаторов (ocmod:refresh)...");
        try {
            $refreshCmd = $this->getApplication()->find('ocmod:refresh');
            $refreshCmd->run($input, $output);
        } catch (\Throwable $e) {
            $io->warning("Не удалось автоматически сбросить OCMOD: " . $e->getMessage());
        }

        $io->success([
            "Модуль '{$code}' успешно обновлен до версии v{$newVersion}!",
            "Установлено/обновлено файлов: {$installedFiles}",
            "Директория OpenCart: {$targetDir}",
        ]);

        return self::SUCCESS;
    }
}
