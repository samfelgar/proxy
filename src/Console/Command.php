<?php

namespace Samfelgar\Proxy\Console;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class Command extends \Symfony\Component\Console\Command\Command
{
    protected SymfonyStyle $output;

    public function __construct(string $name = null)
    {
        parent::__construct($name);

        $this->output = new SymfonyStyle(new ArgvInput(), new ConsoleOutput());
    }
}