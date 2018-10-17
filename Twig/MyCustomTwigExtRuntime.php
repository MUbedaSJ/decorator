<?php
namespace Amu\Bundle\DecoratorBundle\Twig;

use Twig\Extension\RuntimeExtensionInterface;

class MyCustomTwigExtRuntime implements RuntimeExtensionInterface
{
    public function __construct()
    {
        // this simple example doesn't define any dependency, but in your own
        // extensions, you'll need to inject services using this constructor
    }

    public function jsonFilter($stringVars)
    {
        $json = \json_decode( $stringVars,TRUE,512, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_OBJECT_AS_ARRAY);
        if(json_last_error()>0){
            $json = json_last_error_msg();
        }
        return $json;
    }

    public function fqcnFilter($objectVar)
    {
        $reflector=null;

        if(is_array($objectVar)){
            $objectVar= isset($objectVar[0])?$objectVar[0]:$objectVar;
            if($objectVar!==null){
                $reflector = new \ReflectionObject($objectVar);
            }
        }else{
            $reflector = new \ReflectionObject($objectVar);

        }

        if($reflector){
            return (string) $reflector->getName();
        }else{
            return "";
        }

    }

    public function baseUrlFilter($className)
    {
        $reflector = new \ReflectionClass($className);
        return (string) strtolower($reflector->getShortName());

    }
}