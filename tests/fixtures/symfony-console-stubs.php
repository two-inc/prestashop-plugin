<?php

/**
 * The slice of symfony/console the module's console command touches. The
 * offline suite runs without PrestaShop's vendor tree, and phpstan scans only
 * core's own sources, so both load these instead. Shapes follow Symfony 3.4,
 * the version PrestaShop 1.7.6 ships.
 */

namespace Symfony\Component\Console\Input {
    interface InputInterface
    {
        /** @return mixed */
        public function getArgument($name);

        /** @return mixed */
        public function getOption($name);

        /** @return bool */
        public function hasOption($name);

        /** @return bool */
        public function hasParameterOption($values, $onlyParams = false);
    }

    class InputOption
    {
        const VALUE_REQUIRED = 2;
    }

    class InputArgument
    {
        const REQUIRED = 1;
        const OPTIONAL = 2;
    }

    class ArrayInput implements InputInterface
    {
        /** @var array<string,mixed> */
        private $parameters;

        public function __construct(array $parameters)
        {
            $this->parameters = $parameters;
        }

        public function getArgument($name)
        {
            return $this->parameters[$name] ?? null;
        }

        public function getOption($name)
        {
            return $this->parameters['--' . $name] ?? null;
        }

        /** The command's own --shop, plus the --id_shop and --id_shop_group PrestaShop 1.7.6-8 bind onto every command. */
        public function hasOption($name)
        {
            return in_array($name, ['shop', 'id_shop', 'id_shop_group'], true);
        }

        /** A bare option is passed as '--name' => null, as with Symfony's own ArrayInput. */
        public function hasParameterOption($values, $onlyParams = false)
        {
            foreach ((array) $values as $value) {
                if (array_key_exists($value, $this->parameters)) {
                    return true;
                }
            }

            return false;
        }
    }
}

namespace Symfony\Component\Console\Output {
    interface OutputInterface
    {
        public function writeln($messages, $options = 0);
    }

    class BufferedOutput implements OutputInterface
    {
        /** @var string */
        private $buffer = '';

        public function writeln($messages, $options = 0)
        {
            foreach ((array) $messages as $message) {
                $this->buffer .= $message . PHP_EOL;
            }
        }

        public function fetch()
        {
            $content = $this->buffer;
            $this->buffer = '';

            return $content;
        }
    }
}

namespace Symfony\Component\Console\Command {
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Output\OutputInterface;

    class Command
    {
        /** @var string|null */
        private $name;

        public function __construct($name = null)
        {
            if ($name !== null) {
                $this->setName($name);
            }
            $this->configure();
        }

        protected function configure()
        {
        }

        protected function execute(InputInterface $input, OutputInterface $output)
        {
            return 0;
        }

        public function run(InputInterface $input, OutputInterface $output)
        {
            return $this->execute($input, $output);
        }

        public function setName($name)
        {
            $this->name = $name;

            return $this;
        }

        public function getName()
        {
            return $this->name;
        }

        public function setDescription($description)
        {
            return $this;
        }

        public function setHelp($help)
        {
            return $this;
        }

        public function addArgument($name, $mode = null, $description = '', $default = null)
        {
            return $this;
        }

        public function addOption($name, $shortcut = null, $mode = null, $description = '', $default = null)
        {
            return $this;
        }
    }
}
