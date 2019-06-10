<?php

namespace LaravelDoctrine\Migrations\Console;

use Doctrine\DBAL\Connection;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use LaravelDoctrine\Migrations\Configuration\ConfigurationProvider;

class ResetCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'doctrine:migrations:reset
    {--connection= : For a specific connection.}';

    /**
     * @var string
     */
    protected $description = 'Reset all migrations';

    /**
     * @var Connection
     */
    private $connection;

    /**
     * Execute the console command.
     *
     * @param ConfigurationProvider $provider
     */
    public function handle(ConfigurationProvider $provider)
    {
        if (!$this->confirmToProceed()) {
            return;
        }

        $configuration = $provider->getForConnection(
            $this->option('connection')
        );
        $this->connection = $configuration->getConnection();

        $this->safelyDropTables();

        $this->info('Database was reset');
    }

    private function safelyDropTables()
    {
        $this->throwExceptionIfPlatformIsNotSupported();

        $connection = $this->connection;
        $platformName = $connection->getDatabasePlatform()->getName();
        $instructions = $this->getPlatformInstructions()[$platformName];
        $schema = $connection->getSchemaManager();

        $tables = $schema->listTableNames();
        foreach ($tables as $table) {
            $this->safelyDropTable($table, $instructions);
        }

        if ($instructions['dropSequences']) {
            $sequences = $schema->listSequences();
            foreach ($sequences as $sequence) {
                $schema->dropSequence($sequence);
            }
        }
    }

    /**
     * @throws Exception
     */
    private function throwExceptionIfPlatformIsNotSupported()
    {
        $platformName = $this->connection->getDatabasePlatform()->getName();

        if (!array_key_exists($platformName, $this->getPlatformInstructions())) {
            throw new Exception(sprintf('The platform %s is not supported', $platformName));
        }
    }

    /**
     * @param string $table
     */
    private function safelyDropTable($table, $instructions)
    {
        if (isset($instructions['tableIsolation']['enable'])) {
            $statement = sprintf($instructions['isolation']['enable'], $table);
            $this->connection->exec($statement);
        }

        $dropStatement = sprintf($instructions['dropStatement'], $table);
        $this->connection->exec($dropStatement);

        if (isset($instructions['tableIsolation']['disable'])) {
            $statement = sprintf($instructions['isolation']['disable'], $table);
            $this->connection->exec($statement);
        }
    }

    /**
     * @return array
     */
    private function getPlatformInstructions()
    {
        return [
            'mssql' => [
                'dropSequences' => true,
                'tableIsolation' => [
                    'disable' => 'ALTER TABLE %s CHECK CONSTRAINT ALL',
                ],
                'dropStatement' => 'DROP TABLE %s',
            ],
            'mysql' => [
                'dropSequences' => false,
                'tableIsolation' => [
                    'enable' => 'SET FOREIGN_KEY_CHECKS = 1',
                    'disable' => 'SET FOREIGN_KEY_CHECKS = 0',
                ],
                'dropStatement' => 'DROP TABLE %s',
            ],
            'postgresql' => [
                'dropSequences' => true,
                'dropStatement' => 'DROP TABLE IF EXISTS %s CASCADE',
            ],
            'sqlite' => [
                'dropSequences' => false,
                'tableIsolation' => [
                    'enable' => 'PRAGMA foreign_keys = ON',
                    'disable' => 'PRAGMA foreign_keys = OFF',
                ],
                'dropStatement' => 'DROP TABLE %s',
            ],
        ];
    }
}
