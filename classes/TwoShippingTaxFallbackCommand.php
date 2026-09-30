<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/TwoShippingTaxFallbackGate.php';

/**
 * `bin/console twopayment:shipping-tax-fallback enable|disable|status [--shop=<n>]`
 * (TWO-26082), registered through config/services.yml.
 */
class TwoShippingTaxFallbackCommand extends Command
{
    protected function configure(): void
    {
        $this->setName(TwoShippingTaxFallbackGate::COMMAND_NAME)
            ->setDescription('Enable, disable or show the Two Default shipping tax code fallback, globally or for one shop.')
            ->addArgument('action', InputArgument::REQUIRED, 'enable, disable or status')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Write this shop\'s own setting instead of the global one')
            ->setHelp('Without --shop, enable and disable write the global setting. With --shop=<n> they write that shop\'s own setting, which wins over the global one. Every action ends by printing the effective state of each shop.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Not --id_shop: PS 1.7.6-8 bind that onto every command (declaring it throws) and PS 9 drops it. Honour it where bound.
        $idShop = $input->getOption('shop');
        if ($idShop === null && $input->hasOption('id_shop')) {
            $idShop = $input->getOption('id_shop');
            // Core declares it VALUE_OPTIONAL, so a bare --id_shop arrives empty and would otherwise write the global row.
            if ((string) $idShop === '' && $input->hasParameterOption('--id_shop')) {
                $output->writeln('--id_shop needs a shop id. Use --shop=<n> for one shop, or no option for the global setting.');

                return 1;
            }
        }
        if ($input->hasOption('id_shop_group') && $input->getOption('id_shop_group') !== null) {
            $output->writeln('--id_shop_group is not supported. Use --shop=<n> for one shop, or no option for the global setting.');

            return 1;
        }
        list($code, $message) = TwoShippingTaxFallbackGate::run(
            (string) $input->getArgument('action'),
            $idShop === null ? null : (string) $idShop
        );
        $output->writeln($message);

        return $code;
    }
}
