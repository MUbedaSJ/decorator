<?php

namespace Amu\Bundle\DecoratorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('decorator');

        $rootNode->children()
            ->scalarNode("api_secure_role")->defaultValue("")->end()
            ->scalarNode("ajax_secure_role")->defaultValue("")->end()
            ->scalarNode("update_redir_route")->defaultValue("['_index','_view']")->end()
            ->booleanNode("use_translation")->defaultValue(false)->end()
            ->booleanNode("use_labels")->defaultValue(true)->end()
            ->end();
      
        return $treeBuilder;
    }
}
