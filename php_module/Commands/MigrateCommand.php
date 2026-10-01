<?php

namespace Ocm\Commands;

use Ocm\Base\Command;
use Ocm\Base\Input;
use Ocm\Base\Output;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда миграции данных из старого формата в новый.
 */
class MigrateCommand extends Command {
    protected $name = 'migrate';
    protected $description = 'Миграция конфигурации и списков файлов в новый формат .ocm/';

    protected function configure() {
        $this->setName('migrate')
             ->setDescription('Миграция структуры OCM (перенос в .ocm/files.json и .ocm/target)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('OCM: Миграция структуры данных');

        $config = $this->getService('config');

        if ($config->migrateOldFormat()) {
            $io->success([
                "Выполнена успешная миграция данных в формат .ocm/:",
                "- Список файлов перенесен в .ocm/files.json",
                "- Метаданные очищены в opencart-module.json"
            ]);
        } else {
            $io->note("Миграция не требуется: проект уже использует актуальный формат или данных нет.");
        }

        return self::SUCCESS;
    }

    public function handle(Input $input, Output $output) {
        $config = $this->app->getService('config');

        if ($config->migrateOldFormat()) {
            $output->info("Выполнена миграция данных в новый формат:");
            $output->writeln("- Список файлов перемещен в .ocm/files.json");
            $output->writeln("- Метаданные модуля остались в opencart-module.json");
        } else {
            $output->comment("Миграция не требуется или файл opencart-module.json не содержит старых данных.");
        }
    }
}
