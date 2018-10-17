# Documentation :

`need to continue...`

1. Installation

Add repo to your project:

Add to you into composer.json file:
```php
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/MUbedaSJ/decorator"
        }
    ],
    "require": {
        "MUbedaSJ/decorator": "master"
    }
}
```

> $ composer update "MUbedaSJ/decorator"

If you want to use integrated ajax tools, please add routing include this lines to your configuration routes :
<code>
apogee:
    resource: '@DecoratorBundle/Resources/config/routing.yml'
</code>    
[SF 4.0 shoold be "app/config/routes.yaml"; and SF<4 shoold be "app/config/routing.yml" ] 

On "base.html.twig" file 
into block {% stylesheets %}
<code> {%  include '@Decorator/css.html.twig' %} </code>

into block {% javascripts %}
<code> {%  include '@Decorator/js.html.twig' %} </code>
