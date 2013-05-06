<?php

namespace Heyday\Component\Beam;

use Heyday\Component\Beam\Config\BeamConfiguration;
use Heyday\Component\Beam\Deployment\DeploymentProvider;
use Heyday\Component\Beam\Deployment\Rsync;
use Heyday\Component\Beam\Vcs\Git;
use Heyday\Component\Beam\Vcs\VcsProvider;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Process\Process;
use Symfony\Component\OptionsResolver\Options;
use Ssh\SshConfigFileConfiguration;
use Ssh\Session;

/**
 * Class Beam
 * @package Heyday\Component
 */
class Beam
{
    /**
     *
     */
    const PROCESS_TIMEOUT = 300;
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
     * @param array                                  $configs
     * @param array                                  $options
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
     * Validates dynnamic options or options that the options resolver can't validate
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
            if ($this->isServerLockedRemote()) {
                throw new \InvalidArgumentException('Working copy can\'t be used with a locked remote branch');
            }
        } else {
            if (!is_writable($this->getLocalPathFolder())) {
                throw new \InvalidArgumentException(
                    sprintf('The local path "%s" is not writable', $this->getLocalPathFolder())
                );
            }
        }
    }
    /**
     * @return mixed
     */
    public function run()
    {
        if (!$this->isPrepared() && !$this->isWorkingCopy()) {
            $this->prepareLocalPath();
            $this->runPreLocalCommands();
        }

        $this->runPreRemoteCommands();

        $changedFiles = $this->options['deploymentprovider']->deploy($this->options['deploymentoutputhandler']);

        $this->runPostLocalCommands();
        $this->runPostRemoteCommands();

        return $changedFiles;
    }
    /**
     * @return mixed
     */
    public function getChangedFiles()
    {
        if (!$this->isPrepared() && !$this->isWorkingCopy()) {
            $this->prepareLocalPath();
            $this->runPreLocalCommands();
        }
        return $this->options['deploymentprovider']->deploy(
            null,
            true
        );
    }
    /**
     * Ensures that the correct content is at the local path
     */
    protected function prepareLocalPath()
    {
        if ($this->isServerLockedRemote()) {
            $this->options['vcsprovider']->updateBranch($this->options['branch']); //TODO: This might be wrong
        }
        $this->options['vcsprovider']->exportBranch(
            $this->options['branch'],
            $this->getLocalPath()
        );

        $this->setPrepared(true);
    }
    /**
     * Gets the from location for rsync
     *
     * Takes the form "path"
     * @return string
     */
    public function getLocalPath()
    {
        if ($this->isWorkingCopy()) {
            $path = $this->options['srcdir'];
        } else {
            $path = $this->getLocalPathFolder() .
                DIRECTORY_SEPARATOR .
                $this->options['exportdir'];
            // TODO: Think about making this not relative to the srcdir
        }
        return sprintf(
            '%s',
            $path
        );
    }
    /**
     * @return mixed
     */
    public function getRemotePath()
    {
        return $this->getCombinedPath($this->options['deploymentprovider']->getRemotePath());
    }
    /**
     * @param boolean $prepared
     */
    public function setPrepared($prepared)
    {
        $this->prepared = $prepared;
    }
    /**
     * @return boolean
     */
    public function isPrepared()
    {
        return $this->prepared;
    }
    /**
     * Returns whether or not files are being sent to the remote
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
    public function isServerLockedRemote()
    {
        $server = $this->getServer();
        return $this->isServerLocked() && $this->isRemote($server['branch']);
    }
    /**
     * Returns whether or not the branch is remote
     * @return bool
     */
    public function isBranchRemote()
    {
        return $this->isRemote($this->options['branch']);
    }
    /**
     * A helper method to determine if a branch name is remote
     * @param $branch
     * @return bool
     */
    protected function isRemote($branch)
    {
        return substr($branch, 0, 8) === 'remotes/';
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
        return $this->config['servers'][$this->options['remote']];
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
     * @param      $commandline
     * @param null $cwd
     * @param int  $timeout
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
     * A helper method that runs a process and checks its success, erroring if it failed
     * @param Process  $process
     * @param callable $output
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
     * @param $key
     * @param $value
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
    protected function runPostLocalCommands()
    {
        foreach ($this->config['commands'] as $command) {
            if ($command['phase'] == 'post' && $command['location'] == 'local') {
                $this->runLocalCommand($command);
            }
        }
    }
    protected function runPreLocalCommands()
    {
        foreach ($this->config['commands'] as $command) {
            if ($command['phase'] == 'pre' && $command['location'] == 'local') {
                $this->runLocalCommand($command);
            }
        }
    }
    protected function runPreRemoteCommands()
    {
        foreach ($this->config['commands'] as $command) {
            if ($command['phase'] == 'pre' && $command['location'] == 'remote') {
                $this->runRemoteCommand($command);
            }
        }
    }
    protected function runPostRemoteCommands()
    {
        foreach ($this->config['commands'] as $command) {
            if ($command['phase'] == 'post' && $command['location'] == 'remote') {
                $this->runRemoteCommand($command);
            }
        }
    }
    /**
     * @param          $command
     */
    protected function runRemoteCommand($command)
    {
        $server = $this->getServer();
        $configuration = new SshConfigFileConfiguration(
            '~/.ssh/config',
            $server['host']
        );
        $session = new Session(
            $configuration,
            $configuration->getAuthentication(
                null,
                $server['user']
            )
        );
        $exec = $session->getExec();
        call_user_func(
            $this->options['commandoutputhandler'],
            'out',
            $exec->run(
                sprintf(
                    'cd %s; %s',
                    $server['webroot'],
                    $command['command']
                )
            )
        );
    }
    /**
     * @param          $command
     */
    protected function runLocalCommand($command)
    {
        $this->runProcess(
            $this->getProcess(
                $command['command'],
                $this->getLocalPath()
            ),
            $this->options['commandoutputhandler']
        );
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
                'remote',
                'srcdir'
            )
        )->setOptional(
                array(
                    'exportdir',
                    'branch',
                    'path',
                    'dry-run',
                    'checksum',
                    'delete',
                    'workingcopy',
                    'excludesfile',
                    'archive',
                    'compress',
                    'vcsprovider',
                    'deploymentprovider',
                    'deploymentoutputhandler',
                    'commandoutputhandler'
                )
            )->setAllowedValues(
                array(
                    'direction' => array(
                        'up',
                        'down'
                    ),
                    'remote' => array_keys($this->config['servers'])
                )
            )->setDefaults(
                array(
                    'exportdir' => '_temp',
                    'excludesfile' => '.beam-excludes',
                    'path' => false,
                    'dry-run' => false,
                    'delete' => false,
                    'checksum' => true,
                    'workingcopy' => false,
                    'archive' => true,
                    'compress' => true,
                    'delay-updates' => true,
                    'vcsprovider' => function (Options $options) {
                        return new Git($options['srcdir']);
                    },
                    'deploymentprovider' => function () {
                        return new Rsync();
                    },
                    'deploymentoutputhandler' => function ($type, $data) {
                        if ($type == 'out') {
                            echo $data;
                        }
                    },
                    'commandoutputhandler' => function ($type, $data) {
                        if ($type == 'out') {
                            echo $data;
                        }
                    }
                )
            )->setAllowedTypes(
                array(
                    'branch' => 'string',
                    'srcdir' => 'string',
                    'exportdir' => 'string',
                    'excludesfile' => 'string',
                    'dry-run' => 'bool',
                    'checksum' => 'bool',
                    'workingcopy' => 'bool',
                    'archive' => 'bool',
                    'compress' => 'bool',
                    'delay-updates' => 'bool',
                    'vcsprovider' => __NAMESPACE__ . '\Vcs\VcsProvider',
                    'deploymentprovider' => __NAMESPACE__ . '\Deployment\DeploymentProvider',
                    'deploymentoutputhandler' => 'callable',
                    'commandoutputhandler' => 'callable'
                )
            )->setNormalizers(
                array(
                    'branch' => function (Options $options, $value) {
                        return trim($value);
                    },
                    'path' => function (Options $options, $value) {
                        return is_string($value) ? trim($value, '/') : false;
                    },
                    'exportdir' => function (Options $options, $value) {
                        return trim($value, '/');
                    },
                    'excludesfile' => function (Options $options, $value) {
                        return trim($value, '/');
                    },
                    'deploymentprovider' => function (Options $options, $value) use ($that) {
                        if (is_callable($value)) {
                            $value = $value($options);
                        }
                        $value->setBeam($that);
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
}