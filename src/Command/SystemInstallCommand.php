<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Kernel as CoreKernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SystemInstallCommand extends Command
{
    public static $defaultName = 'system:install';

    private string $projectDir;

    public function __construct(string $projectDir)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this->addOption('create-database', null, InputOption::VALUE_NONE, "Create database if it doesn't exist.")
            ->addOption('drop-database', null, InputOption::VALUE_NONE, 'Drop existing database')
            ->addOption('basic-setup', null, InputOption::VALUE_NONE, 'Create storefront sales channel and admin user')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force install even if install.lock exists')
            ->addOption('no-assign-theme', null, InputOption::VALUE_NONE, 'Do not assign the default theme');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new ShopwareStyle($input, $output);

        // set default
        $_ENV['BLUE_GREEN_DEPLOYMENT'] = $_SERVER['BLUE_GREEN_DEPLOYMENT']
            = $_ENV['BLUE_GREEN_DEPLOYMENT']
            ?? $_SERVER['BLUE_GREEN_DEPLOYMENT']
            ?? '1';
        putenv('BLUE_GREEN_DEPLOYMENT=' . $_SERVER['BLUE_GREEN_DEPLOYMENT']);

        $dsn = trim((string) ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL')));
        if ($dsn === '') {
            $output->error("Environment variable 'DATABASE_URL' not defined.");

            return 1;
        }

        if (!$input->getOption('force') && file_exists($this->projectDir . '/install.lock')) {
            $output->comment('install.lock already exists. Delete it or pass --force to do it anyway.');

            return 1;
        }

        $params = parse_url($dsn);
        if ($params === false) {
            $output->error('dsn invalid');

            return 1;
        }

        $path = $params['path'] ?? '/';
        $dbName = substr($path, 1);

        $dsnWithoutDb = sprintf(
            '%s://%s%s:%s',
            $params['scheme'],
            isset($params['pass'], $params['user']) ? ($params['user'] . ':' . $params['pass'] . '@') : '',
            $params['host'],
            $params['port'] ?? 3306
        );

        $parameters = [
            'url' => $dsnWithoutDb,
            'charset' => 'utf8mb4',
        ];

        if (isset($_ENV['DATABASE_SSL_CA'])) {
            $parameters['driverOptions'][\PDO::MYSQL_ATTR_SSL_CA] = $_ENV['DATABASE_SSL_CA'];
        }

        if (isset($_ENV['DATABASE_SSL_CERT'])) {
            $parameters['driverOptions'][\PDO::MYSQL_ATTR_SSL_CERT] = $_ENV['DATABASE_SSL_CERT'];
        }

        if (isset($_ENV['DATABASE_SSL_KEY'])) {
            $parameters['driverOptions'][\PDO::MYSQL_ATTR_SSL_KEY] = $_ENV['DATABASE_SSL_KEY'];
        }

        if (isset($_ENV['DATABASE_SSL_DONT_VERIFY_SERVER_CERT'])) {
            $parameters['driverOptions'][\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        $connection = DriverManager::getConnection($parameters, new Configuration());

        $output->writeln('Prepare installation');
        $output->writeln('');

        $dropDatabase = $input->getOption('drop-database');
        if ($dropDatabase) {
            $connection->executeStatement('DROP DATABASE IF EXISTS `' . $dbName . '`');
            $output->writeln('Drop database `' . $dbName . '`');
        }

        $createDatabase = $input->getOption('create-database') || $dropDatabase;
        if ($createDatabase) {
            $connection->executeStatement('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET `utf8mb4` COLLATE `utf8mb4_unicode_ci`');
            $output->writeln('Created database `' . $dbName . '`');
        }

        $connection->executeStatement('USE `' . $dbName . '`');

        $tables = $connection->executeQuery('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

        if (!\in_array('migration', $tables, true)) {
            $output->writeln('Importing base schema.sql');
            $connection->executeStatement($this->getBaseSchema());
        }

        $output->writeln('');

        $commands = [
            [
                'command' => 'database:migrate',
                'identifier' => 'core',
                '--all' => true,
            ],
            [
                'command' => 'database:migrate-destructive',
                'identifier' => 'core',
                '--all' => true,
                '--version-selection-mode' => 'all',
            ],
            [
                'command' => 'dal:refresh:index',
            ],
            [
                'command' => 'theme:refresh',
            ],
            [
                'command' => 'theme:compile',
                'allowedToFail' => true,
            ],
        ];

        if ($input->getOption('basic-setup')) {
            $commands[] = [
                'command' => 'user:create',
                'username' => 'admin',
                '--admin' => true,
                '--password' => 'shopware',
            ];

            $commands[] = [
                'command' => 'sales-channel:create:storefront',
                '--name' => 'Storefront',
                '--url' => $_SERVER['APP_URL'] ?? 'http://localhost',
            ];

            if (!$input->getOption('no-assign-theme')) {
                $commands[] = [
                    'command' => 'theme:change',
                    'allowedToFail' => true,
                    '--all' => true,
                    'theme-name' => 'Storefront',
                ];
            }
        }

        array_push($commands, [
            'command' => 'assets:install',
        ], [
            'command' => 'cache:clear',
        ]);

        $this->runCommands($commands, $output);

        if (!file_exists($this->projectDir . '/public/.htaccess')
            && file_exists($this->projectDir . '/public/.htaccess.dist')
        ) {
            copy($this->projectDir . '/public/.htaccess.dist', $this->projectDir . '/public/.htaccess');
        }

        touch($this->projectDir . '/install.lock');

        return 0;
    }

    /**
     * @param array<int, array<string, string|bool>> $commands
     */
    private function runCommands(array $commands, OutputInterface $output): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            throw new \RuntimeException('No application initialised');
        }

        foreach ($commands as $parameters) {
            $output->writeln('');

            $command = $application->find((string) $parameters['command']);
            $allowedToFail = $parameters['allowedToFail'] ?? false;
            unset($parameters['command'], $parameters['allowedToFail']);

            try {
                $returnCode = $command->run(new ArrayInput($parameters, $command->getDefinition()), $output);
                if ($returnCode !== 0 && !$allowedToFail) {
                    return $returnCode;
                }
            } catch (\Throwable $e) {
                if (!$allowedToFail) {
                    throw $e;
                }
            }
        }

        return 0;
    }

    private function getBaseSchema(): string
    {
        $kernelClass = new \ReflectionClass(CoreKernel::class);
        $directory = \dirname((string) $kernelClass->getFileName());

        $path = $directory . '/schema.sql';
        if (!is_readable($path) || is_dir($path)) {
            throw new \RuntimeException('schema.sql not found or readable in ' . $directory);
        }

        return (string) file_get_contents($path);
    }
}
