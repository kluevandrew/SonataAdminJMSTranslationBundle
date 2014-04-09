<?php

namespace KA\SonataAdminJMSTranslationBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Class KA\SonataAdminJMSTranslationBundle\DependencyInjection\Configuration
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode    = $treeBuilder->root('ka_sonata_admin_jms_translation');

        $rootNode->children()->arrayNode('remote_repository')
            ->children()
            ->scalarNode('address')->isRequired()->end()
            ->scalarNode('password')->isRequired()->end();


        // Here you should define the parameters that are allowed to
        // configure your bundle. See the documentation linked above for
        // more information on that topic.

        return $treeBuilder;
    }
}
