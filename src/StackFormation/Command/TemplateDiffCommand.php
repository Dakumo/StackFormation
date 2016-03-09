<?php

namespace StackFormation\Command;

use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TemplateDiffCommand extends AbstractCommand
{

    protected function configure()
    {
        $this
            ->setName('stack:diff')
            ->setDescription('Compare the local template and input parameters with the current live stack')
            ->addArgument(
                'stack',
                InputArgument::REQUIRED,
                'Stack'
            );
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $this->interactAskForConfigStack($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $stack = $input->getArgument('stack');

        $effectiveStackName = $this->stackManager->getConfig()->getEffectiveStackName($stack);

        $parameters_live = $this->stackManager->getParameters($effectiveStackName);
        $parameters_local = $this->stackManager->getParametersFromConfig($effectiveStackName, true, true);



        $formatter = new FormatterHelper();
        $output->writeln("\n" . $formatter->formatBlock(['Parameters:'], 'error', true) . "\n");

        $returnVar = $this->printDiff(
            $this->arrayToString($parameters_live),
            $this->arrayToString($parameters_local)
        );
        if ($returnVar == 0) {
            $output->writeln('No changes'."\n");
        }

        $formatter = new FormatterHelper();
        $output->writeln("\n" . $formatter->formatBlock(['Template:'], 'error', true) . "\n");

        $template_live = trim($this->stackManager->getTemplate($effectiveStackName));
        $template_local = trim($this->stackManager->getPreprocessedTemplate($stack));

        $template_live = $this->normalizeJson($template_live);
        $template_local = $this->normalizeJson($template_local);

        $returnVar = $this->printDiff(
            $template_live,
            $template_local
        );
        if ($returnVar == 0) {
            $output->writeln('No changes'."\n");
        }
    }

}
