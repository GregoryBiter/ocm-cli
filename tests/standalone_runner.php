<?php

require_once __DIR__ . '/../vendor/autoload.php';

$testClasses = [
    \Tests\Unit\Services\ConfigServiceTest::class,
    \Tests\Unit\Services\FileSystemServiceTest::class,
    \Tests\Unit\Services\TemplateServiceTest::class,
    \Tests\Unit\Services\DatabaseServiceTest::class,
    \Tests\Unit\Services\ScriptServiceTest::class,
    \Tests\Unit\Services\AgentSkillServiceTest::class,
    \Tests\Unit\Base\ApplicationTest::class,
    \Tests\Integration\Commands\BuildCommandTest::class,
    \Tests\Integration\Commands\InitCommandTest::class,
    \Tests\Integration\Commands\MakeModuleCommandTest::class,
    \Tests\Integration\Commands\AgentCommandsTest::class,
];

$passed = 0;
$failed = 0;

foreach ($testClasses as $className) {
    echo "Running tests in {$className}...\n";
    $reflection = new ReflectionClass($className);
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
        if (strpos($method->getName(), 'test') === 0) {
            $testName = $method->getName();
            $instance = new $className($testName);

            try {
                if (method_exists($instance, 'setUp')) {
                    $setUpMethod = new ReflectionMethod($className, 'setUp');
                    $setUpMethod->setAccessible(true);
                    $setUpMethod->invoke($instance);
                }

                $method->invoke($instance);

                if (method_exists($instance, 'tearDown')) {
                    $tearDownMethod = new ReflectionMethod($className, 'tearDown');
                    $tearDownMethod->setAccessible(true);
                    $tearDownMethod->invoke($instance);
                }

                echo "  \033[32m✔ {$testName}\033[0m\n";
                $passed++;
            } catch (Throwable $e) {
                echo "  \033[31m✘ {$testName}: " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")\033[0m\n";
                $failed++;
                if (method_exists($instance, 'tearDown')) {
                    try {
                        $tearDownMethod = new ReflectionMethod($className, 'tearDown');
                        $tearDownMethod->setAccessible(true);
                        $tearDownMethod->invoke($instance);
                    } catch (Throwable $ignore) {}
                }
            }
        }
    }
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
