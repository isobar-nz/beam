<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Beam;
use Heyday\Component\Beam\DeploymentProvider\DeploymentResult;
use Heyday\Component\Beam\Utils;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Class BeamCommand
 * @package Heyday\Component\Beam\Command
 */
abstract class BeamCommand extends Command
{
    /**
     * @var \Symfony\Component\Console\Helper\HelperInterface
     */
    protected $formatterHelper;
    /**
     * @var \Symfony\Component\Console\Helper\HelperInterface
     */
    protected $progressHelper;
    /**
     * @var \Symfony\Component\Console\Helper\HelperInterface
     */
    protected $deploymentResultHelper;
    /**
     * @var \Symfony\Component\Console\Helper\HelperInterface
     */
    protected $dialogHelper;
    /**
     * @param Application $application
     */
    public function setApplication(Application $application = null)
    {
        parent::setApplication($application);
        if ($application) {
            $helperSet = $this->getHelperSet();
            $this->formatterHelper = $helperSet->get('formatter');
            $this->progressHelper = $helperSet->get('contentprogress');
            $this->deploymentResultHelper = $helperSet->get('deploymentresult');
            $this->dialogHelper = $helperSet->get('dialog');
        }
    }
    /**
     *
     */
    protected function configure()
    {
        $this
            ->addArgument(
                'direction',
                InputArgument::REQUIRED,
                'Valid values are \'up\' or \'down\''
            )
            ->addArgument(
                'target',
                InputArgument::REQUIRED,
                'Config name of target location to be beamed from or to'
            )
            ->addOption(
                'ref',
                'r',
                InputOption::VALUE_REQUIRED,
                'The object in your VCS to beam up (ie. HEAD~1, master, f147a16)'
            )
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'The path to be beamed up or down'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'If set, no files will be transferred'
            )
            ->addOption(
                'no-prompt',
                '',
                InputOption::VALUE_NONE,
                'Skips the dry-run and prompt'
            )
            ->addOption(
                'working-copy',
                '',
                InputOption::VALUE_NONE,
                'When uploading, syncs files from the working copy rather than exported git copy'
            )
            ->addOption(
                'command-prompt',
                '',
                InputOption::VALUE_NONE,
                'Prompts non-required commands'
            )
            ->addOption(
                'tags',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Run the specified tagged commands (wildcards supported).'
            )
            ->addConfigOption();
    }
    /**
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        //Set for use in local closures
        $formatterHelper = $this->formatterHelper;
        $progressHelper = $this->progressHelper;
        $deploymentResultHelper = $this->deploymentResultHelper;
        $dialogHelper = $this->dialogHelper;

        try {

            $beam = new Beam(
                array(
                    $this->getConfig($input)
                ),
                $this->getOptions($input, $output)
            );

            $this->outputSummary(
                $output,
                $formatterHelper,
                $beam
            );

            // Prompt the user with the affected files and a confirmation dialog
            if (!$input->getOption('no-prompt')) {
                $output->writeln(
                    $formatterHelper->formatSection(
                        'info',
                        'Determining list of files that will be modified...'
                    )
                );

                // Get the affected files
                $deploymentResult = $beam->doDryrun();
                // If there are any show them
                $count = count($deploymentResult);
                // If there is more that 1 item there are updates
                if ($count > 0) {
                    // Output the actual changed files and folders
                    $deploymentResultHelper->outputChanges(
                        $formatterHelper,
                        $output,
                        $deploymentResult
                    );
                    // Output a summary of the changes
                    $deploymentResultHelper->outputChangesSummary(
                        $formatterHelper,
                        $output,
                        $deploymentResult
                    );
                    // If it is a dry run we are complete
                    if (!$input->getOption('dry-run')) {
                        // If we have confirmation do the beam
                        if (!$this->isOkay($output, $dialogHelper, $formatterHelper)) {
                            throw new \RuntimeException('User cancelled');
                        }

                        $deleteCount = $deploymentResult->getUpdateCount('deleted');

                        if (
                            $deleteCount > 0
                            && !$this->isOkay(
                                $output,
                                $dialogHelper,
                                $formatterHelper,
                                sprintf(
                                    '%d file%s going to be deleted in this deployment, are you sure this is okay?',
                                    $deleteCount,
                                    $deleteCount === 1 ? ' is' : 's are'
                                ),
                                'no'
                            )
                        ) {
                            throw new \RuntimeException('User cancelled');
                        }

                        // Set the output handler for displaying the progress bar etc
                        $beam->setOption(
                            'deploymentoutputhandler',
                            $this->getDeploymentOutputHandler(
                                $output,
                                $progressHelper,
                                $formatterHelper,
                                $deploymentResult
                            )
                        );

                        // Run the deployment
                        try {
                            $deploymentResult = $beam->doRun($deploymentResult);

                            $deploymentResultHelper->outputChangesSummary(
                                $formatterHelper,
                                $output,
                                $deploymentResult
                            );
                        } catch (\Exception $exception) {
                            if (!$this->handleDeploymentProviderFailure($exception, $output)) {
                                exit(1);
                            }
                        }
                    }
                } else {
                    throw new \RuntimeException('No changed files');
                }
            } else {

                if ($input->getOption('dry-run')) {
                    $changedFiles = $beam->doDryrun();
                } else {
                    $changedFiles = $beam->doRun();
                }

                // Output all changes
                $deploymentResultHelper->outputChanges(
                    $formatterHelper,
                    $output,
                    $changedFiles
                );
                // Output a summary
                $deploymentResultHelper->outputChangesSummary(
                    $formatterHelper,
                    $output,
                    $changedFiles
                );
            }

        } catch (\Exception $e) {
            $this->outputError(
                $output,
                $formatterHelper,
                $e->getMessage()
            );
        }

    }
    /**
     * @param \Exception      $exception
     * @param OutputInterface $output
     * @return bool
     */
    protected function handleDeploymentProviderFailure(\Exception $exception, OutputInterface $output)
    {
        $this->outputMultiline($output, $exception->getMessage(), 'Error', 'error');

        return in_array(
            $this->dialogHelper->askConfirmation(
                $output,
                $this->formatterHelper->formatSection(
                    'Prompt',
                    Utils::getQuestion('The deployment provider threw an exception. Do you want to continue?', 'n'),
                    'error'
                ),
                false
            ),
            array(
                'y',
                'yes'
            )
        );
    }
    /**
     * @param OutputInterface $output
     * @param FormatterHelper $formatterHelper
     * @return callable
     */
    protected function getOutputHandler(
        OutputInterface $output,
        FormatterHelper $formatterHelper
    ) {
        return function ($content, $section = 'info') use ($output, $formatterHelper) {
            $output->writeln(
                $formatterHelper->formatSection(
                    $section,
                    $content
                )
            );
        };
    }
    /**
     * @param OutputInterface  $output
     * @param ProgressHelper   $progressHelper
     * @param FormatterHelper  $formatterHelper
     * @param DeploymentResult $deploymentResult
     * @return callable
     */
    protected function getDeploymentOutputHandler(
        OutputInterface $output,
        ProgressHelper $progressHelper,
        FormatterHelper $formatterHelper,
        DeploymentResult $deploymentResult
    ) {
        $count = count($deploymentResult);

        return function () use (
            $output,
            $progressHelper,
            $formatterHelper,
            $deploymentResult,
            $count
        ) {
            static $steps = 0;
            if ($steps === 0) {
                $progressHelper->setAutoWidth($count);
                // Start the progress bar
                $progressHelper->start($output, $count, 'File: ');
            }
            $progressHelper->advance(1, false, $deploymentResult[$steps]['filename']);
            $steps++;
            if ($steps >= $count) {
                $progressHelper->finish();
            }
        };
    }
    /**
     * @param OutputInterface $output
     * @param DialogHelper    $dialogHelper
     * @param FormatterHelper $formatterHelper
     * @return callable
     */
    protected function getCommandPromptHandler(
        OutputInterface $output,
        DialogHelper $dialogHelper,
        FormatterHelper $formatterHelper
    ) {
        return function ($command) use (
            $output,
            $dialogHelper,
            $formatterHelper
        ) {
            return in_array(
                $dialogHelper->askConfirmation(
                    $output,
                    $formatterHelper->formatSection(
                        $command['command'],
                        Utils::getQuestion('Do you want to run this command?', 'y'),
                        'comment'
                    ),
                    'y'
                ),
                array(
                    'y',
                    'yes'
                )
            );
        };
    }
    /**
     * @param OutputInterface $output
     * @param DialogHelper    $dialogHelper
     * @param FormatterHelper $formatterHelper
     * @return callable
     */
    protected function getCommandFailureHandler(
        OutputInterface $output,
        DialogHelper $dialogHelper,
        FormatterHelper $formatterHelper
    ) {
        return function ($command, $exception, Process $process = null) use (
            $output,
            $dialogHelper,
            $formatterHelper
        ) {
            // Ensure the output of the failed command is shown
            if (OutputInterface::VERBOSITY_VERBOSE !== $output->getVerbosity()) {
                $message = trim($exception->getMessage());

                if (!$message && $process) {
                    $message = trim($process->getErrorOutput()) || trim($process->getOutput());
                }

                if ($message) {
                    $output->writeln($message);
                }
            }

            $output->writeln(
                $formatterHelper->formatSection('Error', 'Error running: ' . $command['command'], 'error')
            );

            if ($command['required']) {
                throw new \RuntimeException('A command marked as required exited with a non-zero status');
            }

            return $dialogHelper->askConfirmation(
                $output,
                $formatterHelper->formatSection(
                    'Prompt',
                    Utils::getQuestion('A command exited with a non-zero status. Do you want to continue', 'yes'),
                    'error'
                )
            );
        };
    }
    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return array
     */
    protected function getOptions(InputInterface $input, OutputInterface $output)
    {
        $formatterHelper = $this->formatterHelper;

        $options = array(
            'direction' => $input->getArgument('direction'),
            'target'    => $input->getArgument('target'),
            'srcdir'    => $this->getSrcDir($input)
        );
        if ($input->getOption('ref')) {
            $options['ref'] = $input->getOption('ref');
        }
        if ($input->getOption('path')) {
            $options['path'] = $input->getOption('path');
        }
        if ($input->getOption('dry-run')) {
            $options['dry-run'] = true;
        }
        if ($input->getOption('working-copy')) {
            $options['working-copy'] = true;
        }
        if ($input->getOption('command-prompt')) {
            $options['commandprompthandler'] = $this->getCommandPromptHandler(
                $output,
                $this->dialogHelper,
                $this->formatterHelper
            );
        }

        $options['commandfailurehandler'] = $this->getCommandFailureHandler(
            $output,
            $this->dialogHelper,
            $this->formatterHelper
        );

        $options['outputhandler'] = $this->getOutputHandler(
            $output,
            $this->formatterHelper
        );

        if ($input->getOption('tags')) {
            $options['command-tags'] = $input->getOption('tags');
        }

        if (OutputInterface::VERBOSITY_VERBOSE === $output->getVerbosity()) {

            $options['targetcommandoutputhandler'] = $options['localcommandoutputhandler'] = function ($type, $data) use (
                $output,
                $formatterHelper
            ) {
                if ($type == 'out') {
                    $output->write(
                        $formatterHelper->formatSection(
                            'command',
                            $data
                        )
                    );
                } elseif ($type == 'err') {
                    $output->write(
                        $formatterHelper->formatSection(
                            'error',
                            $data,
                            'error'
                        )
                    );
                }
            };

        }

        return $options;
    }
    /**
     * @param OutputInterface $output
     * @param                 $formatterHelper
     * @param Beam            $beam
     */
    protected function outputSummary(OutputInterface $output, $formatterHelper, Beam $beam)
    {
        if ($beam->isUp()) {
            $fromMessage = sprintf(
                'SOURCE: %s %s',
                $beam->getLocalPath(),
                $beam->getOption('working-copy') ? '' : '@ <info>' . $beam->getOption('ref') . '</info>'
            );
            $toMessage = sprintf(
                'TARGET: %s',
                $beam->getTargetPath()
            );
        } else {
            $toMessage = sprintf(
                'TARGET: %s',
                $beam->getLocalPath()
            );
            $fromMessage = sprintf(
                'SOURCE: %s',
                $beam->getTargetPath()
            );
        }

        if ($beam->getOption('dry-run')) {
            $action = 'You\'re about do a <comment>dry run</comment> between';
        } else {
            $action = 'You\'re about sync files between:';
        }

        $output->writeln(
            array(
                $formatterHelper->formatSection(
                    'warn',
                    $action,
                    'comment'
                ),
                $formatterHelper->formatSection(
                    'warn',
                    $fromMessage,
                    'comment'
                ),
                $formatterHelper->formatSection(
                    'warn',
                    $toMessage,
                    'comment'
                )
            )
        );

        if ($beam->hasPath()) {
            $pathsMessage = 'PATHS: ';

            foreach ($beam->getOption('path') as $path) {
                $pathsMessage .= "$path\n" . str_repeat(' ', 14);
            }

            $output->writeln(
                $formatterHelper->formatSection(
                    'warn',
                    trim($pathsMessage),
                    'comment'
                )
            );
        }

    }
    /**
     * @param OutputInterface $output
     * @param                 $formatterHelper
     * @param                 $error
     */
    public function outputError(OutputInterface $output, $formatterHelper, $error)
    {
        $output->writeln(
            $formatterHelper->formatSection(
                'error',
                sprintf(
                    '<error>%s</error>',
                    $error
                ),
                'error'
            )
        );
    }
    /**
     * @param OutputInterface $output
     * @param                 $message
     * @param                 $section
     * @param                 $style
     */
    protected function outputMultiline(OutputInterface $output, $message, $section, $style)
    {
        foreach (explode(PHP_EOL, $message) as $line) {
            $output->writeln(
                $this->formatterHelper->formatSection(
                    $section,
                    $line,
                    $style
                )
            );
        }
    }
    /**
     * @param  OutputInterface $output
     * @param                  $dialogHelper
     * @param                  $formatterHelper
     * @param  string          $question
     * @param  string          $default
     * @return mixed
     */
    protected function isOkay(
        OutputInterface $output,
        $dialogHelper,
        $formatterHelper,
        $question = 'Is this okay?',
        $default = 'yes'
    ) {
        //TODO: Respect no-interaction
        return $dialogHelper->askConfirmation(
            $output,
            $formatterHelper->formatSection(
                'prompt',
                Utils::getQuestion(
                    $question,
                    $default
                ),
                'comment'
            ),
            $default[0] === 'y' ? true : false
        );
    }
}
