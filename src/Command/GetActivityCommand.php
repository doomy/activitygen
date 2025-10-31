<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PDO;

class GetActivityCommand extends Command
{
    protected static $defaultName = 'activity:get';

    protected function configure(): void
    {
        $this->setDescription('Get a random activity based on priority');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = getenv('DB_HOST');
        $database = getenv('DB_DATABASE');
        $username = getenv('DB_USERNAME');
        $password = getenv('DB_PASSWORD');

        try {
            $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            while (true) {
                $stmt = $pdo->query('SELECT MAX(priority) as max_priority FROM t_activity');
                $result = $stmt->fetch();
                $maxPriority = (float)$result['max_priority'];

                $minRoll = mt_rand(0, (int)($maxPriority * 10)) / 10;

                $stmt = $pdo->prepare(
                    'SELECT activity, priority FROM t_activity 
                     WHERE priority >= :minRoll 
                     ORDER BY RAND() 
                     LIMIT 1'
                );
                $stmt->execute(['minRoll' => $minRoll]);
                $activity = $stmt->fetch();

                if ($activity) {
                    $output->writeln("Selected activity: {$activity['activity']}");
                    $output->writeln("Priority: {$activity['priority']}");
                    $output->writeln("Minimum roll: {$minRoll}");
                } else {
                    $output->writeln('No activity found');
                    return Command::FAILURE;
                }

                $output->writeln("");
                $output->write("Press any key to continue or Q to exit...");
                
                // Read single character from STDIN
                system('stty cbreak -echo');
                $char = fgetc(STDIN);
                system('stty -cbreak echo');
                
                // Check if read failed (Ctrl+C) or user pressed Q/q
                if ($char === false || strtolower($char) === 'q') {
                    $output->writeln("");
                    return Command::SUCCESS;
                }
                
                $output->writeln("");
            }
        } catch (\PDOException $e) {
            $output->writeln("<error>Database error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
