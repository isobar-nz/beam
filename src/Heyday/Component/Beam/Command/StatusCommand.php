<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Config\BeamConfiguration;
use Heyday\Component\Beam\DeploymentProvider\Deployment;
use Heyday\Component\Beam\VcsProvider\Git;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\InvalidArgumentException;

/**
 * Class ValidateCommand
 * @package Heyday\Component\Beam\Command
 */
class StatusCommand extends Command
{
    /**
     * @var array
     */
    protected $config;

    protected function configure()
    {
        $this->setName('status')
            ->setDescription('Display information about targets')
            ->addArgument(
                'target',
                InputArgument::IS_ARRAY,
                'Target(s) to display information about. Default is all targets.'
            )
            ->addConfigOption();
    }

    /**
     * Get and validate a beam config, and validate arguments
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        try {
            $processor = new Processor();
            $this->config = $processor->processConfiguration(
                new BeamConfiguration(),
                array(
                    $this->getConfig($input)
                )
            );

            $this->validateArguments($input);

        } catch (\Exception $e) {
            $this->outputError($output, $e->getMessage());
            exit(1);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $targets = array_unique($input->getArgument('target'));

        if (count($targets) === 0) {
            $targets = array_keys($this->config['servers']);
        }

        // TODO: factory for VcsProviders that returns a VcsProvider for a given directory
        $git = new Git($this->getSrcDir($input));

        foreach ($targets as $target) {
            $server = $this->config['servers'][$target];

            // Check if HTTP fetch is available?

            // $deploymentProvider = Deployment::deploymentProviderWithType($server['type']);

            // $log = $deploymentProvider->fetch('.beamlog');
            // $lock = $deploymentProvider->fetch('.beamlock');

            // parse file(s)

            // add to output
        }

        // Format and print output
    }

    protected function validateArguments($input)
    {
        $targets = $input->getArgument('target');
        foreach ($targets as $target) {
            if (!isset($this->config['servers'][$target])) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown target "%s". Must be one of "%s"',
                    $target,
                    implode('", "', array_keys($this->config['servers']))
                ));
            }
        }
    }

}
