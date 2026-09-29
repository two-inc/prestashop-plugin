<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/TwoShippingTaxFallbackGate.php';

/**
 * `bin/console twopayment:shipping-tax-fallback enable|disable|status`
 * (TWO-26082), registered through config/admin/services.yml.
 */
class TwoShippingTaxFallbackCommand extends Command
{
    protected function configure(): void
    {
        $this->setName(TwoShippingTaxFallbackGate::COMMAND_NAME)
            ->setDescription('Enable, disable or show the Two Default shipping tax code fallback for this shop.')
            ->addArgument('action', InputArgument::REQUIRED, 'enable, disable or status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        list($code, $message) = TwoShippingTaxFallbackGate::run((string) $input->getArgument('action'));
        $output->writeln($message);

        return $code;
    }
}
