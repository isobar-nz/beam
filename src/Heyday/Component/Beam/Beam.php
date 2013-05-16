<?php

namespace Heyday\Component\Beam;

use Heyday\Component\Beam\Config\BeamConfiguration;
use Heyday\Component\Beam\Deployment\DeploymentProvider;
use Heyday\Component\Beam\Deployment\DeploymentResult;
use Heyday\Component\Beam\Vcs\Git;
use Heyday\Component\Beam\Vcs\VcsProvider;
use Ssh\Session;
use Ssh\SshConfigFileConfiguration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Process\Process;

/**
 * Class Beam
 * @package Heyday\Component
 */
class Beam
{
    /**
     *
     */
    const PROCESS_TIMEOUT = 300; //TODO: Should this be a const?
    /**
     * @var array
     */
    protected $config;
    /**
     * @var array
     */
    protected $options;
    /**
     * @var \Heyday\Component\Beam\Vcs\VcsProvider
     */
    protected $vcsProvider;
    /**
     * @var \Heyday\Component\Beam\Deployment\DeploymentProvider
     */
    protected $deploymentProvider;
    /**
     * @var bool
     */
    protected $prepared = false;

    /**
     * An array of configs, usually just one is expected. This config should be in the format defined in BeamConfigurtion
     * @param array $configs
     * @param array $options
     */
    public function __construct(
        array $configs,
        array $options
    ) {
        $processor = new Processor();

        $this->config = $processor->processConfiguration(
            $this->getConfigurationDefinition(),
            $configs
        );

        $this->setup($options);
    }
    /**
     * Uses the options resolver to set the options to the object from an array
     *
     * Any dynamic options are set in this method and then validated manually
     * This method can be called multiple times, each time it is run it will validate
     * the array provided and set the appropriate options.
     *
     * This might be useful if you prep the options for a command via a staged process
     * for example an interactive command line tool
     * @param  $options
     * @throws \InvalidArgumentException
     */
    public function setup($options)
    {
        $this->options = $this->getOptionsResolver()->resolve($options);

        if (!$this->isWorkingCopy() && !$this->options['vcsprovider']->exists()) {
            throw new \InvalidArgumentException('You can\'t use beam without a vcs.');
        }

        if (!$this->isWorkingCopy() && !$this->options['branch']) {
            if ($this->isServerLocked()) {
                $this->options['branch'] = $this->getServerLockedBranch();
            } else {
                $this->options['branch'] = $this->options['vcsprovider']->getCurrentBranch();
            }
        }

        $this->validateSetup();
    }
    /**
     * Validates dynamic options or options that the options resolver can't validate
     * @throws \InvalidArgumentException
     */
    protected function validateSetup()
    {
        if ($this->options['branch']) {
            if ($this->isServerLocked() && $this->options['branch'] !== $this->getServerLockedBranch()) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Specified branch "%s" doesn\'t match the locked branch "%s"',
                        $this->options['branch'],
                        $this->getServerLockedBranch()
                    )
                );
            }

            $branches = $this->options['vcsprovider']->getAvailableBranches();

            if (!in_array($this->options['branch'], $branches)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Invalid branch "%s" valid options are: %s',
                        $this->options['branch'],
                        '\'' . implode('\', \'', $branches) . '\''
                    )
                );
            }
        }

        if ($this->isWorkingCopy()) {
            if ($this->isTargetLockedRemote()) {
                throw new \InvalidArgumentException('Working copy can\'t be used with a locked remote branch');
            }
        } else {
            if (!is_writable($this->getLocalPathFolder())) {
                throw new \InvalidArgumentException(
                    sprintf('The local path "%s" is not writable', $this->getLocalPathFolder())
                );
            }
        }

        $hasRemoteCommands = $this->hasRemoteCommands();
        $limitations = $this->options['deploymentprovider']->getLimitations();

        if (is_array($limitations)) {

            // Check if remote commands defined when not available
            if ($hasRemoteCommands && in_array(DeploymentProvider::LIMITATION_REMOTECOMMAND, $limitations)) {
                throw new InvalidConfigurationException(
                    'Commands are defined for the location "target" but the selected deployment provider cannot execute remote commands.'
                );
            }

        }

        if ($hasRemoteCommands && !extension_loaded('ssh2')) {
            throw new InvalidConfigurationException(
                'The PHP extension ssh2 is required to run commands on the location "target" but it is not loaded. (You may need to install it).'
            );
        }
    }
    /**
     * @param  Deployment\DeploymentResult $deploymentResult
     * @return mixed
     */
    public function doRun(DeploymentResult $deploymentResult = null)
    {
        if ($this->isUp()) {
            $this->prepareLocalPath();
            $this->runPreTargetCommands();
            $deploymentResult = $this->options['deploymentprovider']->up(
                $this->options['deploymentoutputhandler'],
                false,
                $deploymentResult
            );
            if (!$this->isWorkingCopy()) {
                $this->runPostLocalCommands();
            }
            $this->runPostTargetCommands();
        } else {
            $deploymentResult = $this->options['deploymentprovider']->down(
                $this->options['deploymentoutputhandler'],
                false,
                $deploymentResult
            );
        }

        return $deploymentResult;
    }
    /**
     * @return mixed
     */
    public function doDryrun()
    {
        if ($this->isUp()) {
            $this->prepareLocalPath();
            $deploymentResult = $this->options['deploymentprovider']->up(
                $this->options['deploymentoutputhandler'],
                true
            );
        } else {
            $deploymentResult = $this->options['deploymentprovider']->down(
                $this->options['deploymentoutputhandler'],
                true
            );
        }

        return $deploymentResult;
    }
    /**
     * Ensures that the correct content is at the local path
     */
    protected function prepareLocalPath()
    {
        if (!$this->isPrepared() && !$this->isWorkingCopy() && !$this->isDown()) {
            $this->runOutputHandler(
                $this->options['outputhandler'],
                array(
                    'Preparing local deploy path'
                )
            );

            if ($this->isTargetLockedRemote()) {
                $this->runOutputHandler(
                    $this->options['outputhandler'],
                    array(
                        'Updating remote branch'
                    )
                );
                $this->options['vcsprovider']->updateBranch($this->options['branch']);
            }

            $this->runOutputHandler(
                $this->options['outputhandler'],
                array(
                    'Exporting branch'
                )
            );
            $this->options['vcsprovider']->exportBranch(
                $this->options['branch'],
                $this->getLocalPath()
            );

            $this->setPrepared(true);

            $this->runPreLocalCommands();
            $this->writeLog();
        }
    }
    /**
     * Gets the from location for rsync
     *
     * Takes the form "path"
     * @return string
     */
    public function getLocalPath()
    {
        if ($this->isWorkingCopy() || $this->isDown()) {
            $path = $this->options['srcdir'];
        } else {
            $path = sprintf(
                '/tmp/%s',
                $this->getLocalPathname()
            );
        }

        return sprintf(
            '%s',
            $path
        );
    }
    /**
     * @return string
     */
    public function getLocalPathname()
    {
        return sprintf(
            'beam-%s',
            md5($this->options['srcdir'])
        );
    }
    /**
     * @return mixed
     */
    public function getTargetPath()
    {
        return $this->getCombinedPath($this->options['deploymentprovider']->getTargetPath());
    }
    /**
     * @param boolean $prepared
     */
    public function setPrepared($prepared)
    {
        $this->prepared = $prepared;
    }
    /**
     * @param $key
     * @param $value
     * @return void
     */
    public function setOption($key, $value)
    {
        $this->options = $this->options = $this->getOptionsResolver()->resolve(
            array_merge(
                $this->options,
                array(
                    $key => $value
                )
            )
        );
    }
    /**
     * @return boolean
     */
    public function isPrepared()
    {
        return $this->prepared;
    }
    /**
     * Returns whether or not files are being sent to the target
     * @return bool
     */
    public function isUp()
    {
        return $this->options['direction'] === 'up';
    }
    /**
     * Returns whether or not files are being sent to the local
     * @return bool
     */
    public function isDown()
    {
        return $this->options['direction'] === 'down';
    }
    /**
     * Returns whether or not beam is operating from a working copy
     * @return mixed
     */
    public function isWorkingCopy()
    {
        return $this->options['workingcopy'];
    }
    /**
     * Returns whether or not the server is locked to a branch
     * @return bool
     */
    public function isServerLocked()
    {
        $server = $this->getServer();

        return isset($server['branch']) && $server['branch'];
    }
    /**
     * Returns whether or not the server is locked to a remote branch
     * @return bool
     */
    public function isTargetLockedRemote()
    {
        $server = $this->getServer();

        return $this->isServerLocked() && $this->options['vcsprovider']->isRemote($server['branch']);
    }
    /**
     * Returns whether or not the branch is remote
     * @return bool
     */
    public function isBranchRemote()
    {
        return $this->options['vcsprovider']->isRemote($this->options['branch']);
    }
    /**
     * A helper method for determining if beam is operating with an extra path
     * @return bool
     */
    public function hasPath()
    {
        return $this->options['path'] && $this->options['path'] !== '';
    }
    /**
     * Get the server config we are deploying to.
     *
     * This method is guaranteed to to return a server due to the options resolver and config
     * @return mixed
     */
    public function getServer()
    {
        return $this->config['servers'][$this->options['target']];
    }
    /**
     * Get the locked branch
     * @return mixed
     */
    public function getServerLockedBranch()
    {
        $server = $this->getServer();

        return $this->isServerLocked() ? $server['branch'] : false;
    }
    /**
     * @return string
     */
    protected function getLocalPathFolder()
    {
        return dirname($this->options['srcdir']);
    }
    /**
     * A helper method for combining a path with the optional extra path
     * @param $path
     * @return string
     */
    public function getCombinedPath($path)
    {
        return $this->hasPath() ? $path . DIRECTORY_SEPARATOR . $this->options['path'] : $path;
    }
    /**
     * A helper method that returns a process with some defaults
     * @param          $commandline
     * @param  null    $cwd
     * @param  int     $timeout
     * @return Process
     */
    protected function getProcess($commandline, $cwd = null, $timeout = self::PROCESS_TIMEOUT)
    {
        return new Process(
            $commandline,
            $cwd ? $cwd : $this->options['srcdir'],
            null,
            null,
            $timeout
        );
    }
    /**
     * @param $option
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public function getOption($option)
    {
        if (array_key_exists($option, $this->options)) {
            return $this->options[$option];
        } else {
            throw new \InvalidArgumentException(
                sprintf(
                    'Option \'%s\' doesn\'t exist',
                    $option
                )
            );
        }
    }
    /**
     * @param $config
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public function getConfig($config)
    {
        if (array_key_exists($config, $this->config)) {
            return $this->config[$config];
        } else {
            throw new \InvalidArgumentException(
                sprintf(
                    'Config \'%s\' doesn\'t exist',
                    $config
                )
            );
        }
    }
    /**
     * A helper method that runs a process and checks its success, erroring if it failed
     * @param  Process           $process
     * @param  callable          $output
     * @throws \RuntimeException
     */
    protected function runProcess(Process $process, \Closure $output = null)
    {
        $process->run($output);
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }
    }
    /**
     *
     */
    protected function runPreLocalCommands()
    {
        $commands = $this->getFilteredCommands('pre', 'local');
        if (count($commands)) {
            $this->runOutputHandler(
                $this->options['outputhandler'],
                array(
                    'Running local pre-deployment commands'
                )
            );
            foreach ($commands as $command) {
                $this->runLocalCommand($command);
            }
        }
    }
    /**
     *
     */
    protected function runPreTargetCommands()
    {
        $commands = $this->getFilteredCommands('pre', 'target');
        if (count($commands)) {
            $this->runOutputHandler(
                $this->options['outputhandler'],
                array(
                    'Running target pre-deployment commands'
                )
            );
            foreach ($commands as $command) {
                $this->runTargetCommand($command);
            }
        }
    }
    /**
     *
     */
    protected function runPostLocalCommands()
    {
        $commands = $this->getFilteredCommands('post', 'local');
        if (count($commands)) {
            $this->runOutputHandler(
                $this->options['outputhandler'],
                array(
                    'Running local post-deployment commands'
                )
            );
            foreach ($commands as $command) {
                $this->runLocalCommand($command);
            }
        }
    }
    /**
     *
     */
    protected function runPostTargetCommands()
    {
        $commands = $this->getFilteredCommands('post', 'target');
        if (count($commands)) {
            $this->runOutputHandler(
                $this->options['outputhandler'],
                array(
                    'Running target post-deployment commands'
                )
            );
            foreach ($commands as $command) {
                $this->runTargetCommand($command);
            }
        }
    }
    /**
     * @param $phase
     * @param $location
     * @return bool
     */
    protected function getFilteredCommands($phase, $location)
    {
        $commands = array();

        foreach ($this->config['commands'] as $command) {

            if ($command['phase'] !== $phase) {
                continue;
            }

            if ($command['location'] !== $location) {
                continue;
            }

            if (count($command['servers']) !== 0 && !in_array($this->options['target'], $command['servers'])) {
                continue;
            }

            if (!$command['required']) {

                if ($command['tag'] && (count($this->options['command-tags']) === 0 || !in_array($command['tag'], $this->options['command-tags']))) {
                    continue;
                }

                if (is_callable($this->options['commandprompthandler']) && !$this->options['commandprompthandler']($command)) {
                    continue;
                }

            }

            $commands[] = $command;

        }

        return $commands;
        // if has tag, then default false unless tags specified
    }
    /**
     * @param   $command
     * @throws \RuntimeException
     */
    protected function runTargetCommand($command)
    {
        $this->runOutputHandler(
            $this->options['outputhandler'],
            array(
                $command['command'],
                'command:target'
            )
        );

        $server = $this->getServer();

        try {
            $configuration = new SshConfigFileConfiguration(
                '~/.ssh/config',
                $server['host']
            );
            $authentication = $configuration->getAuthentication(null, $server['user']);

        } catch (\UnexpectedValueException $exception) {
            throw new \RuntimeException(
                "Couldn't find host matching '{$server['host']}' in SSH config file.\n"
                    . "Public key authentication is currently required to execute commands on a target."
            );
        }

        $session = new Session(
            $configuration,
            // TODO: This authentication mechanism should be specifiable
            $authentication
        );

        $exec = $session->getExec();

        try {
            $this->runOutputHandler(
                $this->options['targetcommandoutputhandler'],
                array(
                    $exec->run(
                        sprintf(
                            'cd \'%s\' && %s',
                            $server['webroot'],
                            $command['command']
                        )
                    )
                )
            );
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() == 'The authentication over the current SSH connection failed.') {
                throw new \RuntimeException(
                    'Failed to authenticate over SSH to run a command on the target. This could be caused by a partial'
                        . " definition for '{$server['host']}' in your ssh config file (currently, public key authentication"
                        . ' is required to execute commands on a target).'
                );
            }

            throw $exception;
        }
    }
    /**
     * @param   $command
     */
    protected function runLocalCommand($command)
    {
        $this->runOutputHandler(
            $this->options['outputhandler'],
            array(
                $command['command'],
                'command:local'
            )
        );
        $this->runProcess(
            $this->getProcess(
                $command['command'],
                $this->getLocalPath()
            ),
            $this->options['localcommandoutputhandler']
        );
    }
    /**
     * @param $handler
     * @param $arguments
     * @return bool|mixed
     */
    protected function runOutputHandler($handler, $arguments)
    {
        if (is_callable($handler)) {
            return call_user_func_array($handler, $arguments);
        }

        return false;
    }
    /**
     * This returns an options resolver that will ensure required options are set and that all options set are valid
     * @return OptionsResolver
     */
    protected function getOptionsResolver()
    {
        $that = $this;
        $resolver = new OptionsResolver();
        $resolver->setRequired(
            array(
                'direction',
                'target',
                'srcdir',
                'deploymentprovider'
            )
        )->setOptional(
                array(
                    'branch',
                    'path',
                    'dryrun',
                    'workingcopy',
                    'command-tags',
                    'vcsprovider',
                    'deploymentprovider',
                    'deploymentoutputhandler',
                    'localcommandoutputhandler',
                    'targetcommandoutputhandler',
                    'outputhandler'
                )
            )->setAllowedValues(
                array(
                    'direction' => array(
                        'up',
                        'down'
                    ),
                    'target'    => array_keys($this->config['servers'])
                )
            )->setDefaults(
                array(
                    'path'                       => false,
                    'dryrun'                     => false,
                    'workingcopy'                => false,
                    'command-tags'               => array(),
                    'vcsprovider'                => function (Options $options) {
                        return new Git($options['srcdir']);
                    },
                    'deploymentoutputhandler'    => null,
                    'outputhandler'              => null,
                    'localcommandoutputhandler'  => null,
                    'targetcommandoutputhandler' => null,
                    'commandprompthandler'       => null
                )
            )->setAllowedTypes(
                array(
                    'branch'             => 'string',
                    'srcdir'             => 'string',
                    'dryrun'             => 'bool',
                    'workingcopy'        => 'bool',
                    'command-tags'       => 'array',
                    'vcsprovider'        => __NAMESPACE__ . '\Vcs\VcsProvider',
                    'deploymentprovider' => __NAMESPACE__ . '\Deployment\DeploymentProvider',
                )
            )->setNormalizers(
                array(
                    'branch'                     => function (Options $options, $value) {
                        return trim($value);
                    },
                    'path'                       => function (Options $options, $value) {
                        return is_string($value) ? trim($value, '/') : false;
                    },
                    'deploymentprovider'         => function (Options $options, $value) use ($that) {
                        if (is_callable($value)) {
                            $value = $value($options);
                        }
                        $value->setBeam($that);

                        return $value;
                    },
                    'deploymentoutputhandler'    => function (Options $options, $value) {
                        if ($value !== null && !is_callable($value)) {
                            throw new \InvalidArgumentException('Deployment output handler must be null or callable');
                        }

                        return $value;
                    },
                    'outputhandler'              => function (Options $options, $value) {
                        if ($value !== null && !is_callable($value)) {
                            throw new \InvalidArgumentException('Output handler must be null or callable');
                        }

                        return $value;
                    },
                    'localcommandoutputhandler'  => function (Options $options, $value) {
                        if ($value !== null && !is_callable($value)) {
                            throw new \InvalidArgumentException('Local command output handler must be null or callable');
                        }

                        return $value;
                    },
                    'targetcommandoutputhandler' => function (Options $options, $value) {
                        if ($value !== null && !is_callable($value)) {
                            throw new \InvalidArgumentException('Target command output handler must be null or callable');
                        }

                        return $value;
                    },
                    'commandprompthandler'       => function (Options $options, $value) {
                        if ($value !== null && !is_callable($value)) {
                            throw new \InvalidArgumentException('Command prompt handler must be null or callable');
                        }

                        return $value;
                    }
                )
            );

        return $resolver;
    }

    /**
     * Returns the beam configuration definition for validating the config
     * @return BeamConfiguration
     */
    protected function getConfigurationDefinition()
    {
        return new BeamConfiguration();
    }

    /**
     * Returns true if any commands to run on the remote ("target") are defined
     * @return boolean
     */
    protected function hasRemoteCommands()
    {
        foreach ($this->config['commands'] as $command) {
            if ($command['location'] === 'target') {
                return true;
            }
        }

        return false;
    }
    /**
     *
     */
    protected function writeLog()
    {
        file_put_contents(
            $this->getLocalPath() . '/.beamlog',
            $this->options['vcsprovider']->getLog($this->options['branch'])
        );
    }
}
