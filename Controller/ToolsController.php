<?php

namespace Amu\Bundle\DecoratorBundle\Controller;

use App\Kernel;
use Amu\Bundle\DecoratorBundle\Service\Decorator;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class ToolsController extends Controller
{

    private  $varTwigTemplate = "{{ vars|json_encode( constant('JSON_OBJECT_AS_ARRAY') + constant('JSON_UNESCAPED_UNICODE') + constant('JSON_PRETTY_PRINT') )|raw }}";

    /**
     * @Route("/json_entity_vars/{FQCN}/{debug}", name="jsonEntityVars")
     */
    public function jsonEntityVarsAction($FQCN=null,$debug=false)
    {
        return new Response( $this->get('twig')->createTemplate( $this->varTwigTemplate)->render(array("vars" => $this->get('decorator_service')->getAutoConfig($FQCN,$debug))) );

        /*
        //NON Fonctionnel : Twig_Loader_String n'hexiste plus...
            $twig = new \Twig_Environment(new \Twig_Loader_String());
            $rendered = $this->renderView("{{ vars|json_encode|raw }}",
                array("vars" => $arVars)
            );

        // OLD using a twig view

        return $this->render('@Decorator/tools/entityVars.json.twig', [
            'entityName'=>$entityName,
            'vars'=>$arVars,
            'debug'=>$debug,
        ]);
        */
    }

    /**
     * @Route("/multi_config/{FQCN}/{custom}/{path}/{debug}", name="multiConfigs")
     */
    public function multiConfigsAction($FQCN=null,$custom=array(),$path="",$debug=false)
    {
        return new Response( $this->get('twig')->createTemplate( $this->varTwigTemplate)->render(array("vars" => $this->get('decorator_service')->getMultiConfigs($FQCN,$custom,$path,$debug))) );
    }
    /**
     * @Route("/auto_config/{FQCN}/{debug}", name="yamlConfig")
     */
    public function autoConfigAction($FQCN=null,$debug=false)
    {
        return new Response( $this->get('twig')->createTemplate($this->varTwigTemplate)->render(array("vars" => $this->get('decorator_service')->getAutoConfig($FQCN,$debug))) );
    }
    /**
     * @Route("/yaml_config/{FQCN}/{path}", name="yamlConfig")
     */
    public function yamlConfigAction($FQCN=null,$path="",$debug=false)
    {
        return new Response( $this->get('twig')->createTemplate($this->varTwigTemplate)->render(array("vars" => $this->get('decorator_service')->getYamlConfig($FQCN,$path))) );
    }

    /**
     * @Route("/global_config/", name="globalConfig")
     */
    public function globalConfigAction()
    {
        return new Response( $this->get('twig')->createTemplate($this->varTwigTemplate)->render(array("vars" => $this->get('decorator_service')->getGlobalConfig())) );
    }
    /**
     * @Route("/form_generator/", name="formGenerator")
     */
    public function formGeneratorAction(Request $request, $url, $FQCN,$data,$params="",$debug=false)
    {

        $arItems= [];

        $reflector = new \ReflectionClass($FQCN);
        $entityName= $reflector->getShortName();
        $urlBase= (string) strtolower($entityName);

        $globals = $this->get('decorator_service')->getGlobalConfig();
        $options = [];
        $cfg_entity = [];
        $arActions = [];
        if(is_array($params)){
            $options = isset($params['options'])?$params['options']:[];
            $arActions = isset($params['arActions'])?$params['arActions']:[];
            $cfg_entity=isset($params['entity'])?$params['entity']:[];
        }
        $detectedMode="?";
        $path = "";

        if(is_string($params)){
            if(in_array($params,["","auto"])){
                $detectedMode="auto";
                $arItems=$this->get('decorator_service')->getAutoConfig($FQCN,$debug);
            }elseif(in_array($params,["yaml","yml"])) {
                $detectedMode = "yaml";
                $path = (strpos($params, '.') !== false) ? $params : "";
                $ymlConfig = $this->get('decorator_service')->getYamlConfig($FQCN, $path);
                $arItems = isset($ymlConfig['fields']) ? $ymlConfig['fields'] : [];
                $cfg_entity = isset($ymlConfig['entity']) ? $ymlConfig['entity'] : [];
            }
        }elseif(is_array($params)){
            $detectedMode = "Full-Customizable";
            if(isset($params['fields'])){ // Object {... }
                $arItems=isset($params['fields'])?$params['fields']:[];
                $options=isset($params['options'])?$params['options']:[];
                $cfg_entity=isset($params['entity'])?$params['entity']:[];
            }else{ //  ['fieldA','fieldB','fieldC',...]
                $options=isset($params['options'])?$params['options']:[];
                $semiAutomatic = isset($globals['semiAutomatic'])?$globals['semiAutomatic']:"auto";
                if($semiAutomatic=="auto"){
                    $detectedMode = "Semi-Automatic (auto)";
                    $autoItems= $this->get('decorator_service')->getAutoConfig($FQCN,$debug);
                }else{
                    $detectedMode = "Semi-Automatic (yml)";
                    $ymlConfig=$this->get('decorator_service')->getYamlConfig($FQCN,$path);
                    $options=isset($ymlConfig['options'])?$ymlConfig['options']:[];
                    $autoItems=isset($ymlConfig['fields'])?$ymlConfig['fields']:[];
                    $cfg_entity=isset($ymlConfig['entity'])?$ymlConfig['entity']:[];
                }
                $arItems=[];
                foreach($params as $k=>$f){
                    if(isset($autoItems[$f])){
                        $arItems= array_merge($arItems, [$f =>$autoItems[$f] ] );
                    }else{
                        throw new \Exception("\n Le champs '$f' n'a pas été trouvé dans le fichier de configuration '\decorations\\$urlBase.yml' !\nSi ce champs a été récement rajouter : merci de relancer make:decoration sur l'entité en cours '$entityName' (pour mettre à jour la configuration Yaml)...");
                    }
                }

            }
        }

        $style = isset($options['style'])?$options['style']:"";
        $btSize = isset($options['btSize'])?$options['btSize']:"";
        $width = isset($options['width'])?$options['width']:"100%";

        $new = isset($options['new'])?$options['new']:true;
        $edit = isset($options['edit'])?$options['edit']:true;
        $show = isset($options['show'])?$options['show']:true;
        $delete = isset($options['delete'])?$options['delete']:true;
        $back = isset($options['back'])?$options['back']:true;
        $theme = isset($options['theme'])?$options['theme']:"bootstrap_3_horizontal_layout.html.twig";

        if($debug){
            dump("FQCN = $FQCN ;  MODE = $detectedMode ; theme= $theme \n(items,options,globals)",$arItems,$options,$globals);
        }

        /** @var Form $form */
        $form=$this->get('decorator_service')->generateFormView($url,$arItems,$data);
        $form->handleRequest($request);

        //$this->get('validate_request_listener')->onKernelRequest()
        return $this->render("@Decorator/_form.html.twig",array(

                'globals'=>$globals,
                'arActions'=>$arActions,
                'arItems'=>$arItems,
                'domaine'=>$urlBase,
                'debug'=>$debug,
                'urlBase'=>$urlBase,
                'style'=>$style,
                'btSize'=>$btSize,
                'width'=>$width,
                'new'=>$new,
                'edit'=>$edit,
                'show'=>$show,
                'delete'=>$delete,
                'back'=>$back,
                'theme'=>$theme,
                "$urlBase"=>$data,
                'errors'=>$form->getErrors(true),
                'edit_form'=>$form->createView()
            )
        );
    }

    private function getErrorsFromvalidator(ConstraintViolationListInterface $errors)
    {
        $formattedErrors = [];
        foreach ($errors as $error) {
            $formattedErrors[$error->getPropertyPath()] = $error->getMessage();
        }

        return $formattedErrors;
    }

    /*
    private function getErrorsFromForm(FormInterface $form)
    {
        $errors = array();
        foreach ($form->getErrors() as $error) {
            $errors[] = $error->getMessage();
        }
        foreach ($form->all() as $childForm) {
            if ($childForm instanceof FormInterface) {
                if ($childErrors = $this->getErrorsFromForm($childForm)) {
                    $errors[$childForm->getName()] = $childErrors;
                }
            }
        }

        return $errors;
    }

    private function serializeFormErrors(Form $form){
        $errors = array();
        /**
         * @var  $key
         * @var Form\ $child
         * /
        foreach ($form->all() as $key => $child) {
            if (!$child->isValid()) {
                foreach ($child->getErrors() as $error) {
                    $errors[$key] = $error->getMessage();
                }
            }
        }

        return $errors;
    }
    */
}
