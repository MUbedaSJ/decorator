<?php

namespace MUbedaSJ\Bundle\DecoratorBundle\Twig;

use MUbedaSJ\Bundle\DecoratorBundle\Twig\MyCustomTwigExtRuntime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class JsonExtension extends AbstractExtension
{
    public function getFilters()
    {
        return array(
            // the logic of this filter is now implemented in a different class
            new TwigFilter('json', array(MyCustomTwigExtRuntime::class, 'jsonFilter')),
            new TwigFilter('fqcn', array(MyCustomTwigExtRuntime::class, 'fqcnFilter')),
            new TwigFilter('urlBase', array(MyCustomTwigExtRuntime::class, 'baseUrlFilter')),
        );
    }

    public function getGlobals()
    {
        return array(
            'decorator_use_labels'=> true,
        );
    }
}