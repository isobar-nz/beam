<?php

namespace Heyday\Beam\Config;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Class DeploymentResultConfiguration
 * @package Heyday\Beam\Config
 */
class DeploymentResultConfiguration extends Configuration implements ConfigurationInterface
{
    /**
     * @var array
     */
    protected $reasons = array(
        'checksum',
        'new',
        'size',
        'time',
        'permissions',
        'owner',
        'group',
        'acl',
        'extended',
        'missing',
        'forced'
    );
    /**
     * @var array
     */
    protected $filetypes = array(
        'file',
        'directory',
        'symlink',
        'device',
        'special'
    );

    /**
     * @var array
     */
    protected $updates = array(
        'deleted',
        'sent',
        'received',
        'created',
        'link',
        'attributes'
    );
    /**
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('deploymentresult');

        $rootNode
            ->prototype('array')
                ->children()
                    ->scalarNode('update')->isRequired()
                        ->validate()
                            ->ifNotInArray($this->updates)
                            ->thenInvalid(
                                'Update "%s" is not valid, options are: ' .
                                    $this->getFormattedOptions($this->updates)
                            )
                        ->end()
                    ->end()
                    ->scalarNode('filetype')->isRequired()
                        ->validate()
                            ->ifNotInArray($this->filetypes)
                            ->thenInvalid(
                                'Filetype "%s" is not valid, options are: ' .
                                    $this->getFormattedOptions($this->filetypes)
                            )
                            ->end()
                        ->end()
                    ->arrayNode('reason')->isRequired()
                        ->prototype('scalar')
                            ->validate()
                            ->ifNotInArray($this->reasons)
                            ->thenInvalid(
                                'Reason "%s" is not valid, options are: ' .
                                    $this->getFormattedOptions($this->reasons)
                            )
                            ->end()
                        ->end()
                    ->end()
                    ->scalarNode('filename')->isRequired()->end()
                    ->scalarNode('localfilename')->end()
                ->end();

        return $treeBuilder;
    }
    /**
     * @return array
     */
    public function getUpdates()
    {
        return $this->updates;
    }
}
