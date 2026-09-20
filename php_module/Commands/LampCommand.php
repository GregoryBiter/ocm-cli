<?php

namespace Ocm\Commands;

use Ocm\Base\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда развертывания LAMP-окружения (gb-lamp).
 */
class LampCommand extends Command {
    protected $name = 'lamp';
    protected $description = 'Развертывание LAMP-сервера (gb-lamp) для OpenCart';

    protected function configure() {
        $this->setName('lamp')
             ->setDescription('Развертывание LAMP-сервера (gb-lamp) для разработки OpenCart')
             ->addArgument('target', InputArgument::OPTIONAL, 'Директория для установки LAMP', '.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('OCM: Развертывание окружения LAMP (gb-lamp)');

        /** @var \Ocm\Services\ScriptService $scriptService */
        $scriptService = $this->getService('script');
        if (!$scriptService) {
            $scriptService = new \Ocm\Services\ScriptService();
        }

        $target = $input->getArgument('target') ?: '.';

        $lampScript = $scriptService->resolveScriptPath('lamp');
        if ($lampScript) {
            $io->text("Запуск скрипта <info>{$lampScript}</info> для каталога: <comment>{$target}</comment>...");
            return $scriptService->execute($lampScript, [$target]);
        }

        $io->text("Запуск установки GB-LAMP через curl для каталога: <comment>{$target}</comment>...");
        $escapedTarget = escapeshellarg($target);
        $cmd = "curl -sSL https://raw.githubusercontent.com/GregoryBiter/gb-lamp/main/lamp.sh | bash -s -- {$escapedTarget}";
        passthru($cmd, $exitCode);
        return $exitCode;
    }
}
