<?php

namespace Tests\Integration\Commands;

use Ocm\Commands\InitCommand;
use Ocm\Base\Input;

class InitCommandTest extends CommandTestCase {
    public function testHandleInitializesMetadata() {
        // Mock input to provide default answers for interactive prompts
        $this->output->method('ask')
            ->willReturnCallback(function($question, $default = null) {
                if (strpos($question, 'Имя модуля') !== false) return 'Test Module';
                if (strpos($question, 'Code') !== false) return 'test_module';
                if (strpos($question, 'Версия') !== false) return '1.0.0';
                if (strpos($question, 'Создатель') !== false) return 'ocm';
                if (strpos($question, 'Email') !== false) return 'test@example.com';
                return $default;
            });


        $command = new InitCommand();
        $command->setApplication($this->app);
        
        $input = new Input(['ocm', 'init']);
        $command->handle($input, $this->output);

        $this->assertTrue(file_exists($this->testDir . '/opencart-module.json'));
        $metadata = json_decode(file_get_contents($this->testDir . '/opencart-module.json'), true);
        $this->assertEquals('Test Module', $metadata['module_name']);
        $this->assertEquals('test_module', $metadata['code']);
        $this->assertFileExists($this->testDir . '/.ocm/files.json');
        $this->assertFileDoesNotExist($this->testDir . '/.ocm_files.json');
    }
}
