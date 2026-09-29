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
    }
}
