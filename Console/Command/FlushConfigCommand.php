<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Console\Command;

use Magento\Framework\App\Cache\Manager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FlushConfigCommand extends Command
{
    public function __construct(private readonly Manager $cacheManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('modracx:cache:config')
             ->setDescription('Flush Magento config cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cacheManager->clean(['config']);
        $output->writeln('<info>Config cache flushed successfully.</info>');
        return Command::SUCCESS;
    }
}
