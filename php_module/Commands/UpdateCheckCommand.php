<?php

namespace Ocm\Commands;

use Ocm\Base\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда проверки обновлений модулей (update:check / check:update).
 */
class UpdateCheckCommand extends Command {
    protected $name = 'update:check';
    protected $description = 'Проверка наличия обновлений для модулей на сервере';

    protected function configure() {
        $this->setName('update:check')
             ->setAliases(['check:update'])
             ->setDescription('Проверить наличие обновлений модулей на сервере обновлений')
             ->addOption('server', 's', InputOption::VALUE_OPTIONAL, 'URL сервера обновлений', getenv('UPDATE_SERVER_URL') ?: 'http://localhost:8000')
             ->addOption('key', 'k', InputOption::VALUE_OPTIONAL, 'API ключ', getenv('UPDATE_SERVER_API_KEY') ?: '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('OCM: Проверка обновлений модулей');

        $config = $this->getService('config');
        $serverUrl = rtrim((string)$input->getOption('server'), '/');
        $apiKey = (string)$input->getOption('key');

        $currentDir = $config->getCurrentDir();
        $metadata = $config->loadModuleMetadata();

        $modules = [];
        if (!empty($metadata['code'])) {
            $modules[] = [
                'code' => $metadata['code'],
                'version' => $metadata['version'] ?? '1.0.0',
            ];
        } else {
            $code = basename($currentDir);
            $modules[] = [
                'code' => $code,
                'version' => '1.0.0',
            ];
        }

        $url = "{$serverUrl}/api/v1/modules/check-batch";
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
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['modules' => $modules]),
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

        $results = $json['results'] ?? [];
        $rows = [];
        $updatesFound = 0;

        foreach ($modules as $mod) {
            $code = $mod['code'];
            $curVer = $mod['version'];
            $info = $results[$code] ?? null;

            if ($info && isset($info['status']) && $info['status'] === 'update_available') {
                $updatesFound++;
                $rows[] = [
                    $code,
                    $curVer,
                    "<info>{$info['version']}</info>",
                    "<fg=yellow;options=bold>Доступно обновление</>",
                ];
            } else {
                $latest = $info['version'] ?? $curVer;
                $rows[] = [
                    $code,
                    $curVer,
                    $latest,
                    '<fg=green>Актуальная версия</>',
                ];
            }
        }

        $io->table(['Код модуля', 'Текущая версия', 'Версия на сервере', 'Статус'], $rows);

        if ($updatesFound > 0) {
            $io->note("Найдено обновлений: {$updatesFound}. Для обновления выполните: ocm update");
        } else {
            $io->success("Все проверенные модули имеют актуальные версии.");
        }

        return self::SUCCESS;
    }
}
