<?php

namespace Tests\Integration\Commands;

use Ocm\Commands\MakeModuleCommand;
use Ocm\Services\TemplateService;
use Symfony\Component\Console\Tester\CommandTester;

class MakeModuleCommandTest extends CommandTestCase {
    public function testCreatesOnlyCanonicalFilesRegistry() {
        $templateDir = $this->testDir . '/.ocm/templates/registry_test/upload/admin/controller';
        mkdir($templateDir, 0777, true);
        file_put_contents($templateDir . '/test.php', '<?php // test');

        $services = new \ReflectionProperty($this->app, 'services');
        $services->setAccessible(true);
        $values = $services->getValue($this->app);
        $values['template'] = new TemplateService($values['filesystem']);
        $services->setValue($this->app, $values);

        $command = new MakeModuleCommand();
        $command->setApplication($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(0, $tester->execute([
            'name' => 'registry_module',
            '--template' => 'registry_test',
        ], ['interactive' => false]));

        $moduleDir = $this->testDir . '/registry_module';
        $this->assertFileExists($moduleDir . '/.ocm/files.json');
        $this->assertFileDoesNotExist($moduleDir . '/.ocm_files.json');
        $this->assertSame(['files' => ['admin/controller/test.php']],
            json_decode(file_get_contents($moduleDir . '/.ocm/files.json'), true));
    }
}
