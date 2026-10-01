<?php

namespace Ocm\Services;

class OpenCartService {
    private static $defaultDb = null;
    private static $connections = [];

    public static function bootstrapOpenCart() {
        if (!defined('OPENCART_DIR') || !is_dir(OPENCART_DIR)) {
            return null;
        }

        $config_file = OPENCART_DIR . '/config.php';
        if (!file_exists($config_file)) {
            return null;
        }

        self::loadEnvFile(OPENCART_DIR . '/.env');
        self::loadEnvFile(CURRENT_DIR . '/.env');

        if (!isset($_ENV['OC_PATH']) || empty($_ENV['OC_PATH'])) {
            $_ENV['OC_PATH'] = OPENCART_DIR;
        }

        require_once $config_file;

        if (!defined('DIR_SYSTEM')) {
            return null;
        }

        require_once DIR_SYSTEM . 'startup.php';

        $registry = new \Registry();

        $loader = new \Loader($registry);
        $registry->set('load', $loader);

        if (defined('DB_DRIVER') && defined('DB_HOSTNAME') && defined('DB_USERNAME') && defined('DB_DATABASE')) {
            try {
                $db = new \DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, defined('DB_PORT') ? DB_PORT : null);
                $registry->set('db', $db);
                return $registry;
            } catch (\Exception $e) {
                echo "Ошибка подключения к БД: " . $e->getMessage() . "\n";
                return null;
            }
        }

        return null;
    }

    public static function loadEnvFile($path) {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return;

        $vars = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $vars[$name] = $value;
        }

        // Рекурсивное раскрытие ${VAR}
        $changed = true;
        $maxPasses = 5;
        while ($changed && $maxPasses-- > 0) {
            $changed = false;
            foreach ($vars as $k => $v) {
                $expanded = preg_replace_callback('/\$\{([a-zA-Z0-9_]+)\}/', function($m) use ($vars) {
                    return $vars[$m[1]] ?? (getenv($m[1]) ?: '');
                }, $v);
                if ($expanded !== $v) {
                    $vars[$k] = $expanded;
                    $changed = true;
                }
            }
        }

        foreach ($vars as $name => $value) {
            if (!isset($_ENV[$name])) {
                $_ENV[$name] = $value;
                putenv("{$name}=" . $value);
            }
        }
    }

    public static function getOpenCartDb() {
        if (self::$defaultDb === null) {
            $registry = self::bootstrapOpenCart();
            if ($registry && $registry->has('db')) {
                self::$defaultDb = $registry->get('db');
            }
        }

        return self::$defaultDb;
    }

    public static function getOpenCartDbForPath($target_path) {
        $real_target_path = realpath($target_path);
        if (!$real_target_path) {
            return null;
        }

        if (isset(self::$connections[$real_target_path])) {
            return self::$connections[$real_target_path];
        }

        $config_file = $real_target_path . '/config.php';
        if (!file_exists($config_file)) {
            return null;
        }

        $config_content = file_get_contents($config_file);
        if ($config_content === false) {
            return null;
        }

        $extract = function($name) use ($config_content) {
            $pattern = "/define\\('\\Q{$name}\\E'\\s*,\\s*'([^']*)'\\)/";
            if (preg_match($pattern, $config_content, $matches)) {
                return $matches[1];
            }
            return null;
        };

        $driver = $extract('DB_DRIVER');
        $hostname = $extract('DB_HOSTNAME');
        $username = $extract('DB_USERNAME');
        $password = $extract('DB_PASSWORD');
        $database = $extract('DB_DATABASE');
        $port = $extract('DB_PORT');

        if (!$driver || !$hostname || !$username || !$database) {
            return null;
        }

        try {
            if (!class_exists('DB')) {
                $db_library = $real_target_path . '/system/library/db.php';
                if (file_exists($db_library)) {
                    require_once $db_library;
                }
            }

            $adaptor_class = 'DB\\' . $driver;
            if (!class_exists($adaptor_class)) {
                $adaptor_file = $real_target_path . '/system/library/db/' . $driver . '.php';
                if (file_exists($adaptor_file)) {
                    require_once $adaptor_file;
                }
            }

            $db = new \DB($driver, $hostname, $username, (string)$password, $database, $port ?: null);
            self::$connections[$real_target_path] = $db;
            return $db;
        } catch (\Exception $e) {
            echo "Ошибка подключения к БД ({$real_target_path}): " . $e->getMessage() . "\n";
            return null;
        }
    }

    public static function cleanDirectory($dir) {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'index.html' || $item === '.gitignore') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::cleanDirectory($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    public static function refreshModifications($target_path) {
        $target_path = rtrim((string)$target_path, '/');
        $mod_dirs = [
            $target_path . '/system/storage/modification',
            $target_path . '/system/modification'
        ];

        foreach ($mod_dirs as $mod_dir) {
            if (is_dir($mod_dir)) {
                echo "  Очистка кэша модификаций ({$mod_dir})...\n";
                self::cleanDirectory($mod_dir);
            }
        }

        if (self::runAdminModificationRefresh($target_path)) {
            echo "  Модификаторы успешно обновлены через OpenCart Modification Refresh.\n";
        } else {
            echo "  Предупреждение: не удалось выполнить admin refresh модификаторов.\n";
        }
    }

    public static function runAdminModificationRefresh($target_path) {
        $target_path = rtrim((string)$target_path, '/');
        $admin_config = $target_path . '/admin/config.php';

        if (!is_file($admin_config)) {
            return false;
        }

        // Если обнаружен Docker Compose и запущен контейнер php-apache, выполняем внутри него
        $dbService = new DatabaseService();
        $composeFile = $dbService->findDockerComposeFile($target_path);
        if ($composeFile && $dbService->isDockerServiceRunning($composeFile, 'php-apache')) {
            return self::runAdminModificationRefreshViaDocker($composeFile);
        }

        $temp_script = tempnam(sys_get_temp_dir(), 'ocm_mod_refresh_');
        if ($temp_script === false) {
            return false;
        }

        $script = <<<'SCRIPT'
<?php
$target_path = isset($argv[1]) ? rtrim($argv[1], '/') : '';
if ($target_path === '' || !is_dir($target_path)) {
    fwrite(STDERR, "Invalid target path.\n");
    exit(1);
}

$load_env = function($path) {
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '') {
            continue;
        }

        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }
};

$load_env(dirname($target_path) . '/.env');
$load_env($target_path . '/.env');

// Рекурсивное раскрытие ${VAR}
foreach ($_ENV as $k => $v) {
    if (is_string($v) && strpos($v, '${') !== false) {
        $_ENV[$k] = preg_replace_callback('/\$\{([a-zA-Z0-9_]+)\}/', function($m) {
            return $_ENV[$m[1]] ?? (getenv($m[1]) ?: '');
        }, $v);
        putenv("{$k}=" . $_ENV[$k]);
    }
}

if (!empty($_ENV['OC_DB_HOST']) && $_ENV['OC_DB_HOST'] === 'db') {
    $_ENV['OC_DB_HOST'] = '127.0.0.1';
    putenv('OC_DB_HOST=127.0.0.1');
}

if (empty($_ENV['OC_PATH']) || !is_dir($_ENV['OC_PATH'])) {
    $_ENV['OC_PATH'] = $target_path;
    putenv('OC_PATH=' . $target_path);
}

if (empty($_ENV['OC_URL'])) {
    $_ENV['OC_URL'] = 'http://localhost';
    putenv('OC_URL=http://localhost');
}

$admin_config = $target_path . '/admin/config.php';
if (!is_file($admin_config)) {
    fwrite(STDERR, "admin/config.php not found.\n");
    exit(1);
}

if (!isset($_SERVER['REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}
if (!isset($_SERVER['HTTPS'])) {
    $_SERVER['HTTPS'] = false;
}

require_once $admin_config;
require_once DIR_SYSTEM . 'startup.php';

$registry = new Registry();

$config = new Config();
$config->load('default');
$config->load('admin');
$registry->set('config', $config);

$event = new Event($registry);
$registry->set('event', $event);

if ($config->has('action_event')) {
    foreach ($config->get('action_event') as $key => $value) {
        foreach ($value as $priority => $action) {
            $event->register($key, new Action($action), $priority);
        }
    }
}

$loader = new Loader($registry);
$registry->set('load', $loader);

$request = new Request();
$registry->set('request', $request);

$response = new Response();
$response->addHeader('Content-Type: text/html; charset=utf-8');
$registry->set('response', $response);

$db = new DB($config->get('db_engine'), $config->get('db_hostname'), $config->get('db_username'), $config->get('db_password'), $config->get('db_database'), $config->get('db_port'));
$registry->set('db', $db);

$session = new Session($config->get('session_engine'), $registry);
$registry->set('session', $session);
$session->start('');

$token = token(32);
$session->data['user_token'] = $token;
$request->get['user_token'] = $token;

$user_query = $db->query("SELECT `user_id` FROM `" . DB_PREFIX . "user` WHERE `status` = '1' ORDER BY `user_id` ASC LIMIT 1");
if ($user_query->num_rows) {
    $session->data['user_id'] = (int)$user_query->row['user_id'];
}

$registry->set('cache', new Cache($config->get('cache_engine'), $config->get('cache_expire')));
$registry->set('url', new Url($config->get('site_url'), $config->get('site_ssl')));
$registry->set('language', new Language($config->get('language_directory')));
$registry->set('document', new Document());
$registry->set('user', new Cart\User($registry));

if ($config->has('config_autoload')) {
    foreach ($config->get('config_autoload') as $value) {
        $loader->config($value);
    }
}
if ($config->has('language_autoload')) {
    foreach ($config->get('language_autoload') as $value) {
        $loader->language($value);
    }
}
if ($config->has('library_autoload')) {
    foreach ($config->get('library_autoload') as $value) {
        $loader->library($value);
    }
}
if ($config->has('model_autoload')) {
    foreach ($config->get('model_autoload') as $value) {
        $loader->model($value);
    }
}

$actionRoute = 'marketplace/modification/refresh';
if (defined('DIR_APPLICATION') && is_file(DIR_APPLICATION . 'controller/extension/modification.php')) {
    $actionRoute = 'extension/modification/refresh';
}
$action = new Action($actionRoute);
$result = $action->execute($registry, []);

if ($result instanceof Exception) {
    fwrite(STDERR, $result->getMessage() . "\n");
    exit(1);
}

exit(0);
SCRIPT;

        if (file_put_contents($temp_script, $script) === false) {
            @unlink($temp_script);
            return false;
        }

        $php_binary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $command = escapeshellarg($php_binary) . ' ' . escapeshellarg($temp_script) . ' ' . escapeshellarg($target_path) . ' 2>&1';

        $output = [];
        $exit_code = 1;
        exec($command, $output, $exit_code);

        @unlink($temp_script);

        if ($exit_code !== 0) {
            if (!empty($output)) {
                echo "  admin refresh log: " . implode("\n  ", $output) . "\n";
            }
            return false;
        }

        return true;
    }

    /**
     * Выполнение admin refresh модификаторов внутри Docker-контейнера php-apache.
     */
    public static function runAdminModificationRefreshViaDocker($composeFile) {
        $script = <<<'SCRIPT'
<?php
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTPS'] = false;
$target_path = '/var/www/html';
require_once $target_path . '/admin/config.php';
require_once DIR_SYSTEM . 'startup.php';

$registry = new Registry();
$config = new Config();
$config->load('default');
$config->load('admin');
$registry->set('config', $config);

$event = new Event($registry);
$registry->set('event', $event);

if ($config->has('action_event')) {
    foreach ($config->get('action_event') as $key => $value) {
        foreach ($value as $priority => $action) {
            $event->register($key, new Action($action), $priority);
        }
    }
}

$loader = new Loader($registry);
$registry->set('load', $loader);
$request = new Request();
$registry->set('request', $request);
$response = new Response();
$registry->set('response', $response);

$db = new DB($config->get('db_engine'), $config->get('db_hostname'), $config->get('db_username'), $config->get('db_password'), $config->get('db_database'), $config->get('db_port'));
$registry->set('db', $db);

$session = new Session($config->get('session_engine'), $registry);
$registry->set('session', $session);
$session->start('');

$token = token(32);
$session->data['user_token'] = $token;
$request->get['user_token'] = $token;

$prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';
$user_query = $db->query("SELECT user_id FROM " . $prefix . "user WHERE status = '1' ORDER BY user_id ASC LIMIT 1");
if ($user_query && $user_query->num_rows) {
    $session->data['user_id'] = (int)$user_query->row['user_id'];
}

$registry->set('cache', new Cache($config->get('cache_engine'), $config->get('cache_expire')));
$registry->set('url', new Url($config->get('site_url'), $config->get('site_ssl')));
$registry->set('language', new Language($config->get('language_directory')));
$registry->set('document', new Document());
$registry->set('user', new Cart\User($registry));

if ($config->has('config_autoload')) {
    foreach ($config->get('config_autoload') as $value) {
        $loader->config($value);
    }
}
if ($config->has('language_autoload')) {
    foreach ($config->get('language_autoload') as $value) {
        $loader->language($value);
    }
}
if ($config->has('library_autoload')) {
    foreach ($config->get('library_autoload') as $value) {
        $loader->library($value);
    }
}
if ($config->has('model_autoload')) {
    foreach ($config->get('model_autoload') as $value) {
        $loader->model($value);
    }
}

$actionRoute = 'marketplace/modification/refresh';
if (defined('DIR_APPLICATION') && is_file(DIR_APPLICATION . 'controller/extension/modification.php')) {
    $actionRoute = 'extension/modification/refresh';
}
$action = new Action($actionRoute);
$result = $action->execute($registry, []);
if ($result instanceof Exception) {
    fwrite(STDERR, $result->getMessage() . "\n");
    exit(1);
}
echo "OCM_REFRESH_SUCCESS\n";
exit(0);
SCRIPT;

        $cmd = sprintf('docker compose -f %s exec -T php-apache php 2>&1', escapeshellarg($composeFile));
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            return false;
        }

        fwrite($pipes[0], $script);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        return ($exitCode === 0 && strpos($output, 'OCM_REFRESH_SUCCESS') !== false);
    }

    public static function getInstallXmlPath() {
        $baseDir = defined('CURRENT_DIR') ? CURRENT_DIR : getcwd();
        $candidates = [
            $baseDir . '/install.xml',
            $baseDir . '/index.xml',
            $baseDir . '/ocmod.xml',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return $baseDir . '/install.xml';
    }

    public static function parseInstallXmlMetadata() {
        $xml_file = self::getInstallXmlPath();
        if (!is_file($xml_file)) {
            return null;
        }

        $xml_content = file_get_contents($xml_file);
        if ($xml_content === false || $xml_content === '') {
            return null;
        }

        if (class_exists('\DOMDocument')) {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            if (@$dom->loadXML($xml_content)) {
                $read = function($tag, $default = '') use ($dom) {
                    $node = $dom->getElementsByTagName($tag)->item(0);
                    return $node ? trim($node->nodeValue) : $default;
                };

                return [
                    'xml' => $xml_content,
                    'code' => $read('code', ''),
                    'name' => $read('name', ''),
                    'version' => $read('version', ''),
                    'author' => $read('author', ''),
                    'link' => $read('link', '')
                ];
            }
        }

        $extractTag = function($tag, $default = '') use ($xml_content) {
            if (preg_match('#<' . preg_quote($tag, '#') . '(?:\s+[^>]*)?>(.*?)</' . preg_quote($tag, '#') . '>#is', $xml_content, $matches)) {
                return trim(strip_tags($matches[1]));
            }
            return $default;
        };

        return [
            'xml' => $xml_content,
            'code' => $extractTag('code', ''),
            'name' => $extractTag('name', ''),
            'version' => $extractTag('version', ''),
            'author' => $extractTag('author', ''),
            'link' => $extractTag('link', '')
        ];
    }

    public static function validateModuleMetadataContract(&$metadata, &$errors) {
        $errors = [];
        if (!is_array($metadata)) {
            $errors[] = 'opencart-module.json должен содержать объект JSON';
            return false;
        }

        if (array_key_exists('files', $metadata)) {
            $errors[] = "Поле 'files' запрещено в opencart-module.json (используйте .ocm/files.json)";
        }

        return empty($errors);
    }

    public static function inferCodeFromMetadata($metadata) {
        if (!is_array($metadata)) {
            return basename(CURRENT_DIR);
        }

        if (!empty($metadata['controller'])) {
            $controller = trim((string)$metadata['controller'], '/');
            $parts = explode('/', $controller);
            $type = count($parts) >= 3 ? $parts[count($parts) - 2] : 'module';
            $name = count($parts) >= 1 ? $parts[count($parts) - 1] : basename(CURRENT_DIR);
            return preg_replace('/[^a-z0-9_]+/i', '_', strtolower($type . '_' . $name));
        }

        if (!empty($metadata['type']) && !empty($metadata['name'])) {
            return preg_replace('/[^a-z0-9_]+/i', '_', strtolower($metadata['type'] . '_' . $metadata['name']));
        }

        return basename(CURRENT_DIR);
    }

    public static function getExistingModificationVersion($db, $code) {
        $safe_code = $db->escape($code);
        $query = $db->query("SELECT `version` FROM `" . DB_PREFIX . "modification` WHERE `code` = '" . $safe_code . "' LIMIT 1");
        if ($query->num_rows > 0 && !empty($query->row['version'])) {
            return $query->row['version'];
        }
        return '';
    }

    public static function resolveModuleIdentity($target_path) {
        $metadata = \load_module_metadata();
        $errors = [];
        self::validateModuleMetadataContract($metadata, $errors);

        $install_xml = self::parseInstallXmlMetadata();
        $db = self::getOpenCartDbForPath($target_path);

        $code = !empty($metadata['code']) ? $metadata['code'] : '';
        if ($code === '' && $install_xml && !empty($install_xml['code'])) {
            $code = $install_xml['code'];
        }
        if ($code === '') {
            $code = self::inferCodeFromMetadata($metadata);
        }

        $name = '';
        if (!empty($metadata['module_name'])) {
            $name = $metadata['module_name'];
        } elseif (!empty($metadata['name'])) {
            $name = $metadata['name'];
        } elseif ($install_xml && !empty($install_xml['name'])) {
            $name = $install_xml['name'];
        } else {
            $name = $code;
        }

        $version = '';
        if (!empty($metadata['version'])) {
            $version = $metadata['version'];
        } elseif ($install_xml && !empty($install_xml['version'])) {
            $version = $install_xml['version'];
        } elseif ($db) {
            $version = self::getExistingModificationVersion($db, $code);
        }
        if ($version === '') {
            $version = '0.0.0';
        }

        return [
            'code' => $code,
            'name' => $name,
            'version' => $version,
            'metadata' => is_array($metadata) ? $metadata : [],
            'install_xml' => $install_xml,
            'errors' => $errors
        ];
    }

    public static function ensureOcmTables($db) {
        $db->query("\n        CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ocm_modules` (\n            `module_id` INT(11) NOT NULL AUTO_INCREMENT,\n            `code` VARCHAR(64) NOT NULL,\n            `name` VARCHAR(255) NOT NULL,\n            `type` VARCHAR(32) NOT NULL DEFAULT 'module',\n            `installed_version` VARCHAR(32) NOT NULL DEFAULT '0.0.0',\n            `source` VARCHAR(32) NOT NULL DEFAULT 'ocm_cli',\n            `metadata_json` MEDIUMTEXT NOT NULL,\n            `status` TINYINT(1) NOT NULL DEFAULT 1,\n            `installed_at` DATETIME NOT NULL,\n            `updated_at` DATETIME NOT NULL,\n            PRIMARY KEY (`module_id`),\n            UNIQUE KEY `code` (`code`)\n        ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;\n    ");

        $db->query("\n        CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ocm_module_files` (\n            `file_id` INT(11) NOT NULL AUTO_INCREMENT,\n            `module_code` VARCHAR(64) NOT NULL,\n            `file_path` VARCHAR(500) NOT NULL,\n            `file_hash` VARCHAR(64) NOT NULL DEFAULT '',\n            `installed_at` DATETIME NOT NULL,\n            `updated_at` DATETIME NOT NULL,\n            `removed_at` DATETIME NULL DEFAULT NULL,\n            PRIMARY KEY (`file_id`),\n            KEY `module_code` (`module_code`),\n            KEY `file_path` (`file_path`)\n        ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;\n    ");

        $db->query("\n        CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ocm_module_versions` (\n            `version_id` INT(11) NOT NULL AUTO_INCREMENT,\n            `module_code` VARCHAR(64) NOT NULL,\n            `version` VARCHAR(32) NOT NULL,\n            `package_hash` VARCHAR(64) NOT NULL DEFAULT '',\n            `index_hash` VARCHAR(64) NOT NULL DEFAULT '',\n            `changelog` TEXT NOT NULL,\n            `source` VARCHAR(32) NOT NULL DEFAULT 'ocm_cli',\n            `published_at` DATETIME NOT NULL,\n            `applied_at` DATETIME NOT NULL,\n            PRIMARY KEY (`version_id`),\n            KEY `module_code` (`module_code`)\n        ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;\n    ");

        $db->query("\n        CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ocm_update_packages` (\n            `package_id` INT(11) NOT NULL AUTO_INCREMENT,\n            `code` VARCHAR(64) NOT NULL,\n            `name` VARCHAR(255) NOT NULL,\n            `description` TEXT,\n            `version` VARCHAR(32) NOT NULL,\n            `author` VARCHAR(255) DEFAULT NULL,\n            `author_url` VARCHAR(255) DEFAULT NULL,\n            `category` VARCHAR(64) DEFAULT 'module',\n            `opencart_version` VARCHAR(32) DEFAULT NULL,\n            `dependencies` TEXT DEFAULT NULL,\n            `archive_structure` enum('opencart', 'direct') DEFAULT 'opencart',\n            `file_path` VARCHAR(500) NOT NULL,\n            `file_size` INT(11) DEFAULT 0,\n            `file_hash` VARCHAR(64) DEFAULT NULL,\n            `package_hash` VARCHAR(64) DEFAULT NULL,\n            `index_hash` VARCHAR(64) DEFAULT NULL,\n            `image` VARCHAR(255) DEFAULT NULL,\n            `demo_url` VARCHAR(255) DEFAULT NULL,\n            `documentation_url` VARCHAR(255) DEFAULT NULL,\n            `support_url` VARCHAR(255) DEFAULT NULL,\n            `price` DECIMAL(15,4) DEFAULT 0.0000,\n            `downloads` INT(11) DEFAULT 0,\n            `rating` DECIMAL(3,2) DEFAULT 0.00,\n            `reviews` INT(11) DEFAULT 0,\n            `status` TINYINT(1) DEFAULT 1,\n            `featured` TINYINT(1) DEFAULT 0,\n            `sort_order` INT(3) DEFAULT 0,\n            `date_added` DATETIME NOT NULL,\n            `date_modified` DATETIME NOT NULL,\n            PRIMARY KEY (`package_id`),\n            UNIQUE KEY `code` (`code`),\n            KEY `status` (`status`),\n            KEY `featured` (`featured`),\n            KEY `category` (`category`)\n        ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;\n    ");
    }

    public static function handleOcmod($target_path) {
        $install_xml = self::parseInstallXmlMetadata();
        if (!$install_xml) {
            echo "  Ошибка: install.xml обязателен и должен быть валидным XML.\n";
            return false;
        }

        $db = self::getOpenCartDbForPath($target_path);
        if (!$db) {
            echo "  Предупреждение: Не удалось подключиться к БД для установки модификатора.\n";
            return false;
        }

        $code = $install_xml['code'] !== '' ? $install_xml['code'] : basename(CURRENT_DIR);
        $name = $install_xml['name'] !== '' ? $install_xml['name'] : $code;
        $version = $install_xml['version'] !== '' ? $install_xml['version'] : '0.0.0';
        $author = $install_xml['author'] !== '' ? $install_xml['author'] : 'Unknown';
        $link = $install_xml['link'];

        $db->query("DELETE FROM `" . DB_PREFIX . "modification` WHERE `code` = '" . $db->escape($code) . "'");
        $db->query("INSERT INTO `" . DB_PREFIX . "modification` SET 
        `code` = '" . $db->escape($code) . "',
        `name` = '" . $db->escape($name) . "',
        `author` = '" . $db->escape($author) . "',
        `version` = '" . $db->escape($version) . "',
        `link` = '" . $db->escape($link) . "',
        `xml` = '" . $db->escape($install_xml['xml']) . "',
        `status` = 1,
        `date_added` = NOW()");

        echo "  Модификатор '{$code}' установлен из install.xml.\n";
        self::refreshModifications($target_path);
        return true;
    }

    public static function removeModuleFromDb($target_path, $module_code) {
        $db = self::getOpenCartDbForPath($target_path);
        if (!$db || !$module_code) {
            return;
        }

        self::ensureOcmTables($db);

        $safe_code = $db->escape($module_code);
        $db->query("DELETE FROM `" . DB_PREFIX . "ocm_modules` WHERE `code` = '" . $safe_code . "'");
        $db->query("UPDATE `" . DB_PREFIX . "ocm_module_files` SET `removed_at` = NOW() WHERE `module_code` = '" . $safe_code . "' AND `removed_at` IS NULL");
    }

    public static function syncWithDb($target_path, $files) {
        $db = self::getOpenCartDbForPath($target_path);
        if (!$db) {
            return;
        }

        self::ensureOcmTables($db);

        $identity = self::resolveModuleIdentity($target_path);
        $metadata = $identity['metadata'];

        if (!empty($identity['errors'])) {
            foreach ($identity['errors'] as $error) {
                echo "  Ошибка контракта: {$error}\n";
            }
            return;
        }

        $code = $identity['code'];
        $name = $identity['name'];
        $version = $identity['version'];
        $module_type = !empty($metadata['type']) ? $metadata['type'] : 'module';
        $install_xml_hash = $identity['install_xml'] ? sha1($identity['install_xml']['xml']) : '';

        $metadata['code'] = $code;
        $metadata['version'] = $version;
        $metadata['name'] = $name;

        $db->query("INSERT INTO `" . DB_PREFIX . "ocm_modules` SET
        `code` = '" . $db->escape($code) . "',
        `name` = '" . $db->escape($name) . "',
        `type` = '" . $db->escape($module_type) . "',
        `installed_version` = '" . $db->escape($version) . "',
        `source` = 'ocm_cli',
        `metadata_json` = '" . $db->escape(json_encode($metadata, JSON_UNESCAPED_UNICODE)) . "',
        `status` = 1,
        `installed_at` = NOW(),
        `updated_at` = NOW()
        ON DUPLICATE KEY UPDATE
        `name` = VALUES(`name`),
        `type` = VALUES(`type`),
        `installed_version` = VALUES(`installed_version`),
        `source` = 'ocm_cli',
        `metadata_json` = VALUES(`metadata_json`),
        `status` = 1,
        `updated_at` = NOW()");

        $safe_code = $db->escape($code);
        $db->query("DELETE FROM `" . DB_PREFIX . "ocm_module_files` WHERE `module_code` = '" . $safe_code . "'");

        foreach ($files as $relative_path) {
            $target_file = rtrim($target_path, '/') . '/' . ltrim($relative_path, '/');
            $file_hash = is_file($target_file) ? sha1_file($target_file) : '';

            $db->query("INSERT INTO `" . DB_PREFIX . "ocm_module_files` SET
                `module_code` = '" . $safe_code . "',
                `file_path` = '" . $db->escape($relative_path) . "',
                `file_hash` = '" . $db->escape($file_hash) . "',
                `installed_at` = NOW(),
                `updated_at` = NOW(),
                `removed_at` = NULL");
        }

        if ($version !== '') {
            $safe_version = $db->escape($version);
            $safe_package_hash = $db->escape($install_xml_hash);
            $safe_index_hash = $db->escape(sha1($safe_code . ':' . $safe_version . ':' . count($files)));

            $exists = $db->query("SELECT `version_id` FROM `" . DB_PREFIX . "ocm_module_versions` WHERE `module_code` = '" . $safe_code . "' AND `version` = '" . $safe_version . "' LIMIT 1");
            if (!$exists->num_rows) {
                $db->query("INSERT INTO `" . DB_PREFIX . "ocm_module_versions` SET
                    `module_code` = '" . $safe_code . "',
                    `version` = '" . $safe_version . "',
                    `package_hash` = '" . $safe_package_hash . "',
                    `index_hash` = '" . $safe_index_hash . "',
                    `changelog` = '',
                    `source` = 'ocm_cli',
                    `published_at` = NOW(),
                    `applied_at` = NOW()");
            } else {
                $db->query("UPDATE `" . DB_PREFIX . "ocm_module_versions` SET
                    `package_hash` = '" . $safe_package_hash . "',
                    `index_hash` = '" . $safe_index_hash . "',
                    `applied_at` = NOW()
                    WHERE `version_id` = '" . (int)$exists->row['version_id'] . "'");
            }
        }
    }

    public static function removeModificationByCode($target_path, $code) {
        try {
            $databaseService = new \Ocm\Services\DatabaseService();
            $creds = $databaseService->getCredentials($target_path);
            if (!$creds || empty($code)) return;

            $pdo = $databaseService->getPdo($target_path);
            $prefix = $creds['prefix'];
            $stmt = $pdo->prepare("DELETE FROM `{$prefix}modification` WHERE `code` = :code");
            $stmt->execute([':code' => $code]);
        } catch (\Throwable $e) {
            // Игнорируем ошибки при удалении
        }
    }
}
