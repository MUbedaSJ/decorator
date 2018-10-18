<?php

namespace Amu\Bundle\DecoratorBundle\Service;

use Amu\Bundle\ReferensBundle\Controller\AjaxController;
use App\Entity\Groupe;
use Egulias\EmailValidator\Exception\ExpectingCTEXT;
use FOS\CKEditorBundle\Form\Type\CKEditorType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormView;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;

/**
 * Service d'introspection de la configuration ORM (entités/variables/définitions)
 * @author Michel UBÉDA <michel.ubeda@univ-amu.fr>
 * @version 1.0
 * @since 07/09/2018
 */
class Decorator
{
    /**
     * @var ContainerInterface
     */
    public $container;

    /**
     * @var Logger
     */
    public $logger;

    /**
     * @var Environment
     */
    public $twig;

    /**
     * @var string
     */
    private $decorationPath;

    function __construct(ContainerInterface $container, Logger $logger, Environment $twig)
    {
        $this->container = $container;
        $this->logger = $logger;
        $this->twig = $twig;
        $this->decorationPath = $this->container->getParameter('kernel.root_dir') . "/decorations/";
        /*
	$this->url=$container->getParameter("decorator.varsConfig");
        if(in_array($this->url,array("","http://server:port/path_to_service_container/services/Referens?wsdl"))){
            throw new \Exception("CONFIGURATION: l'url du Web Service Referens doit être spécifié !\n\"referens.ws_url: '...'\"\n");
        }
*/
    }

    /**
     * Ajoute dans les fichiers logs des traces d'informations de debug formatées
     * @param string $titre Le titre de la rubrique affiché (defaut: "")
     * @param object/array $infos variable ou informations formaté à afficher
     * @param bool $isError Formatté la fenêtre d'informatoion comme une ERREUR  (true:false defaut=false)
     */
    protected function trace($titre, $infos = array(), $isError = false)
    {
        if ($isError == false) {
            $this->logger->addInfo("DecoratorBundle: $titre", $infos);
        } else {
            $this->logger->addError("DecoratorBundle: $titre", $infos);
        }
    }

    /**
     * List all $className entity vars
     * @param string $FQCN_ClassName définition complète de la classe avec son namespace (FQCN)
     * @return array
     */
    public function getReflexionEntityVars($FQCN_ClassName) {
        $arVars=array();
        $reflector = new \ReflectionClass($FQCN_ClassName);
        foreach (get_class_vars($FQCN_ClassName) as $aVar){
            if($reflector->getProperty($aVar)!=""){
                $arVars[$aVar]=  $reflector->getProperty($aVar)->getDocComment();
            }
        }

        if(count($arVars)>0){
            ksort($arVars, SORT_NATURAL );
        }

        return $arVars;
    }


    private function dismount($object) {
        $array = array();
        if($object!==null){
            $reflectionClass = new \ReflectionClass(get_class($object));
            foreach ($reflectionClass->getProperties() as $property) {
                $property->setAccessible(true);
                $array[$property->getName()] = $property->getValue($object);
                $property->setAccessible(false);
            }
        }
        return $array;
    }

    /**
     * @param array $arItems
     * @param $data
     * @return FormView
     * @throws \Exception
     */
    public function generateFormView($url,$arItems,$data,$arActions)
    {
        $classReflector = new \ReflectionObject($data);
        $entityName= strtolower($classReflector->getShortName());
        $dataClass= $classReflector->getName();

        $blockName=strtolower(str_replace("\\Entity\\","_",$dataClass));

        //$formType=str_replace("Entity","Form",$FQCN);

        $useTranslator= $this->container->getParameter('decorator.use_translation');

        $arOptions= array(
            'action'=>$url,
            'translation_domain'=> $useTranslator?$entityName :false,
            'allow_extra_fields'=>true,
            'error_bubbling'=>true,
            'data_class'=> $dataClass,
            'block_name'=> $blockName,
//            'compound' => true,
//            'inherit_data' => true,
        );


        $builder =  $this->container->get('form.factory')->createNamed($blockName,FormType::class, $data, $arOptions);
        $arData=$this->dismount($data);

        foreach ($arItems as $aItem){


            $arAttrs=[];
            $isVisible=false;

            if(isset($aItem['visible'])){
                $isVisible =$aItem['visible'];
            }

            if($isVisible){

                $FQCN="";
                $formType="";
                $entity="";

                $name=$aItem['value'];
                $label=$aItem['label'];

                if($useTranslator){
//                    $label=$entityName.'.'.$name;
                    $label=$this->container->get('translator')->trans($entityName.'.'.$name,array(),$entityName,"fr");
                }

                $curValue=isset($arData[$name])?$arData[$name]:null;

                $arAttrs= array(
                    //                'id'=>$entity.'_'.$name,
                    //                'name'=> $name,
                    //                'full_name'=> "$entity"."[$name]",
                    'disabled'=>false,
                    'label'=>$label,
                    'data'=> $curValue,
                    'error_bubbling'=>true,
                    "method" => "POST",
                    'mapped'=>true,
                    'property_path'=>$name,
//                    'id'=>$blockName."_".$name,
//                    'unique_block_prefix'=>"_".$blockName."_".$name,
//                    'cache_key'=>"_".$blockName."_".$name,
//                    'full_name'=>$blockName."[$name]",

                    //                'required'=>false,

                    //'translation_domain'=>$entityName, // global on parent forms
                    'compound' => true,
                    'inherit_data' => false, 'data_class'=>null,
                    // OU
                    //'inherit_data' => true, 'data_class'=>$dataClass,
                    'auto_initialize'=>true,
                    'trim'=>true,
                    'required'=>false,
                );
                $type=null;
                $required=false;


                $ro=false;
                if(isset($aItem['readonly'])){
                    $ro=$aItem['readonly'];
                }
                if($ro) {
                    //$arAttrs['attr']['disabled'] = true;
                    $arAttrs['attr']['readonly'] = true;
                }
                if(isset($aItem['type'])){

                    $curType=$aItem['type'];
                    $isMany=false;

                    // Conversion de TYPE
                    switch ($curType){

                        case 'ckeditor':
                            $type = CKEditorType::class;
                            break;

                        case 'integer':
                            $arAttrs['compound'] = false;
                            break;

                        case 'formated':
                            $type = ChoiceType::class;
                            $arChoices=[];
                            if(isset($aItem['formats'])){
                                $arChoices=$aItem['formats'];
                            }
                            $arAttrs['choices']=$arChoices;
                            $arAttrs['compound'] = false;
                            $arAttrs['attr']['class'] = isset($arAttrs['attr']['class'])?$arAttrs['attr']['class']:"" ." select2";
                            //$arAttrs['attr']['style'] = isset($arAttrs['attr']['style'])?$arAttrs['attr']['style']:"" ." width:auto;";
                            break;

                        case '':
                        case 'string':
                            $type = TextType::class;
                            $arAttrs['compound'] = false;
                            break;

                        case 'datetime':
                            $type = DateTimeType::class;
                            $arAttrs['compound'] = true;
                            $arAttrs['date_widget']='single_text';
                            $arAttrs['format']="d/m/Y H:i:s";
                            $arAttrs['time_widget']='single_text';
                            if($ro){
                                //$arAttrs['attr']['disabled'] = true;
                                // $arAttrs['attr']['readonly'] = true; déjà init cf. ci-dessus
                                //$arAttrs['attr']['class'] = isset($arAttrs['attr']['class'])?$arAttrs['attr']['class']:"" ." datetimeRO ui-state-disabled";
                            }else{
                                $arAttrs['attr']['class'] = isset($arAttrs['attr']['class'])?$arAttrs['attr']['class']:"" ." datetimepickerWT";

                            }
                            break;


                        case 'text':
                        case 'popover':
                        case 'tooltip':
                            $type= TextareaType::class;
                            $arAttrs['compound'] = false;
                            break;
                    }

                    if(in_array($curType,['text','popover','tooltip'] )){
                        $arAttrs['attr']['style']='width:100%;max-width:100%;';
                        $arAttrs['attr']['class']="strLimitCounter";
                    }
                    else{
                        $arAttrs['attr']['style']='width:auto%;max-width:100%;';

                    }

                    if(in_array($curType,['collection','entity'])){
                        $curClassname=$aItem['targetEntity'];
                        $reflector = new \ReflectionClass($curClassname);
                        $entity= strtolower($reflector->getShortName());
                        $FQCN= $reflector->getName();
                        if(!isset($aItem['targetEntity'])){
                            throw new \Exception("Veuillez spécifier le paramétre 'targetEntity' du champs '$name' (type=$curType) dans le fichier de configuration '\decorations\\$entity.yml'");
                        }
                    }

                    if($curType=='collection'){
                        $formType=str_replace("Entity","Form",$FQCN)."Type";

                        $type=CollectionType::class; //'Symfony\Component\Form\Extension\Core\Type\CollectionType';
                        $arAttrs['constraints']=new Valid();
                        $arAttrs['error_bubbling']=true;
                        $arAttrs["entry_type"]= $formType;
                        $arAttrs['allow_add']=true;
                        $arAttrs['allow_delete']=true;
                        $arAttrs['prototype']=true;
                        $arAttrs['by_reference']=false; // pour generer/remplir le prototype
                        $arAttrs['compound']=true;
                        $isMany=true;

                    }
                    elseif($curType=='entity'){
                        $type=EntityType::class;
                        $arAttrs['class']=$FQCN;
                        if(!isset($aItem['choice_label'])){
                            throw new \Exception("Veuillez spécifier le paramétre 'choice_label' du champs '$name' (type=$curType)");
                        }
                        $arAttrs['compound']=true;
                        $arAttrs['choice_label']= $aItem['choice_label'];


                        if(isset($aItem['relation'])){
                            if($aItem['relation']=="ManyToOne") {
                                $isMany = true;
                            }
                        }
                    }

                }

                if(isset($aItem['lenght'])){
                    if($aItem['lenght']>0){
                        $arAttrs['attr']['maxlength']=$aItem['lenght'];
                    }
                }

                if(isset($aItem['maxlength'])){
                    if($aItem['maxlength']>0){
                        $arAttrs['attr']['maxlength']=$aItem['maxlength'];
                    }
                }

                if(isset($aItem['multiple']) || $isMany){
                    if($isMany){
                        $arAttrs['attr']['multiple']= true;
                    }else{
                        $arAttrs['attr']['multiple']=$aItem['multiple'];
                    }
                    $arAttrs['attr']['class'] = isset($arAttrs['attr']['class'])?$arAttrs['attr']['class']:"" ." select2";
                    $arAttrs['attr']['style'] = isset($arAttrs['attr']['style'])?$arAttrs['attr']['style']:"" ." width:100%;max-width:100%;";
                }


                foreach( ['size','max','min','required','placeholder'] as $aAttr){
                    if(isset($aItem[$aAttr])){
                        if($aAttr=='required'){
                            if($aItem[$aAttr]==true){
                                $required=true;
                            }
                        }elseif($aItem[$aAttr]!=""){
                            $arAttrs['attr'][$aAttr]  = $aItem[$aAttr];
                        }

                    }
                }

                if(isset($aItem['assert']['pattern'])){
                    if($aItem['assert']['pattern']!=""){
                        $arAttrs['attr']['pattern']=$aItem['assert']['pattern'];
                    }
                }


                if(isset($aItem['nullable'])){
                    if($aItem['nullable']==false){
                        $required=true;
                    }
                }

                if(isset($aItem['required'])){
                    if($aItem['required']==true){
                        $required=true;
                    }
                }

                if($required){
                    $arAttrs['required']=true;
                    $arAttrs['attr']['required']=true;
                    $arAttrs['label_attr']['class'] = isset($arAttrs['label_attr']['class'])?$arAttrs['label_attr']['class']:"" ." required";
                }

                $builder->add($name,$type,$arAttrs);

            }



        }

        if(count($arActions)){
            foreach ($arActions as $key=>$aAction){
                $arAttrs=[];

                $name=isset($aAction['name'])?$aAction['name']:$key;
                if($name!=""){
                    ///  "attr", "auto_initialize", "block_name", "disabled", "label", "label_format", "translation_domain", "validation_groups"
                    $arAttrs= array(
                        'disabled'=>false,
                        'label'=>isset($aAction['label'])?$aAction['label']:"",
                        'attr'=>[]
                    );

                    $type=ButtonType::class;
                    if(isset($aAction['type'])){
                        if($aAction['type']=='submit'){
                            $type=SubmitType::class;
                        }
                    }

                    foreach (array('class','name', 'id','onclick','title') as $oneAttr){
                        if(isset($aAction[$oneAttr])){
                            $arAttrs['attr'][$oneAttr] = $aAction[$oneAttr];
                        }
                    }

                    $builder->add($name,$type,$arAttrs);

                }
            }
        }

        return $builder;

    }

    public function getGlobalConfig(){
        $globalsFile = $this->decorationPath . "globals.yml";
        $globals = array();

        if (@file_exists($globalsFile)) {
            $globals = Yaml::parseFile($globalsFile);
        }else{
            throw new \Exception("File Not found ($globalsFile) \n please launch 'make:decoration' command to generate...");
        }

        foreach (array('use_translation','api_secure_role','ajax_secure_role','update_redir_route','use_translation','use_labels') as $oneParam){
            $globals['config'][$oneParam] =$this->container->getParameter("decorator.$oneParam");
        }

        return $globals;

    }

    public function getYamlConfig($FQCN_ClassName,$path=""){
        $arConfigs=array();
        if($path==""){
            $path=$this->decorationPath;
        }
        // dernière parie du $FQCN_ClassName en minuscule
        $entityName= strtolower( substr($FQCN_ClassName, strrpos($FQCN_ClassName,"\\")+1 ) );
        $configFile = $path."$entityName.yml";
        if(@file_exists($configFile)) {
            $arConfigs=array_merge($arConfigs, Yaml::parseFile($configFile));
        }else{
            throw new \Exception("File Not found ($configFile) \n please launch 'make:decoration' command to generate...");
        }
        return $arConfigs;
    }

    public function getAutoConfig($FQCN_ClassName,$debug=false){
        $arVars=array();
        $reflector = new \ReflectionClass($FQCN_ClassName);
        $useTranslation=$this->container->getParameter('decorator.use_translation');

        $domaine = strtolower($reflector->getShortName());
        foreach ($reflector->getProperties() as $aProp){
            $name = (string) $aProp->getName();
            if($name!=""){

                $label = ($useTranslation)? $this->container->get('translator')->trans("$domaine.$name",array(),$domaine): $name;
                //
//                $arVars['fields'][$name]=$name;
                $arVars[$name]['value']=$name;
                $arVars[$name]['type']="";
                $arVars[$name]['label']= $label;
                $arVars[$name]['placeholder']= $label;

                $arVars[$name]['visible']=true;
                $arVars[$name]['class']="";
                $arVars[$name]['align']="left";
                $arVars[$name]['min']="";
                $arVars[$name]['max']="";
                $arVars[$name]['maxlength']="";
                $arVars[$name]['size']="";
                $arVars[$name]['required']=false;
                $arVars[$name]['pattern']="";

                $arVars[$name]['format']="";
                $arVars[$name]['formats']=[];

                if($name=="id"){
                    $arVars[$name]['visible']=false;
                    $arVars[$name]['readonly']=true;
                }

                $docComments=(string)$aProp->getDocComment();

                $assertsParts=null;
                if(preg_match_all('/@Assert.(.*)\((.*)\)/', (string)$aProp->getDocComment(),$assertsParts)){
                    if(isset($assertsParts[1])){
                        switch($assertsParts[1])
                        {
                            case "NotNull":
                            case "NotBlank":
                                $arVars[$name]['required']=true;
                                break;

                            case "Regex":
                                /**
                                 * Assert\Regex(
                                 *     pattern     = "/^[a-z]+$/i",
                                 *     htmlPattern = "^[a-zA-Z]+$"
                                 * )
                                 */
                                $arVars[$name]['pattern']=$assertsParts[2];
                                break;

                            // @TODO à contimuer

                        }
                    }

                }
                $ormParts=null;
                if(preg_match_all('/@ORM.(.*)\((.*)\)/', (string)$aProp->getDocComment(),$ormParts)){
                    $ormInfos=join("\n",$ormParts[0]);
                }else{
                    $ormInfos=$docComments;
                }

                /* @see https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/annotations-reference.html#annref_column */
                $defFields="(type|name|columnDefinition|targetEntity|mappedBy|inversedBy)";
                $arMatches=null;
                if(preg_match_all('/('.$defFields.')="{0,1}([^\'"\t\n\r\f]+)"{0,1}/',$ormInfos,$arMatches)) {
                    foreach ($arMatches[1] as $k=>$field){
                        $arVars[$name][$field] = isset($arMatches[3][$k]) ? $arMatches[3][$k] : "?";
                    }
                }

                $arMatches=null;
                if(preg_match_all('/((length|precision|scale|unique|nullable))=([^\'\),"\t\n\r\f]+)[, )]{0,1}/', $ormInfos,$arMatches)) {
                    foreach ($arMatches[1] as $k=>$field){
                        $value=isset($arMatches[3][$k]) ? $arMatches[3][$k] : "?";
                        if(($field!=='unique')&&($field!=='nullable')){
                            $value=intval($value);
                            if($field=='length'){
                                $arVars[$name]['size'] = $value;
                            }
                        }else{
                            if($field=='nullable'){
                                // prev init ? true par les Assert NotNull ou NotBlank?
                                $value=($value=="true");
                                if($value==false){
                                    if( $arVars[$name]['required']== false){
                                        $arVars[$name]['required'] = true;
                                    }
                                }
                            }
                        }
                        $arVars[$name][$field] = $value;
                    }
                }

                // spécific "options" like json object
                $arMatches=null;
                if(preg_match_all('/options=(\{{0,1}([^\t\n\r\f]+)\}{0,1})/', $ormInfos,$arMatches)) {
                    $arVars[$name]['options'] = isset($arMatches[0][0]) ? $arMatches[0][0] : null;
                }

                // spécific "cascade" like json object
                $arMatches=null;
                //if(preg_match_all('/cascade=\{{0,1}[^\t\n\r\f{}]{0,1}["\'](\w){0,1}["\']\}{0,1}/', $ormInfos,$arMatches)) {
                if(preg_match_all('/cascade=(\{[^}]+\})/', $ormInfos,$arMatches)) {
                    $cascade= isset($arMatches[1][0])?($arMatches[1][0]):$arMatches;
                    $arVars[$name]['cascade'] =json_decode( strtr($cascade,array("{"=>"[","}"=>"]")),true);
                }

                $arMatches=null;
                if(preg_match_all('/((Many|One)To(Many|One))/', $ormInfos,$arMatches)) {
                    $arVars[$name]['relation']= isset($arMatches[0][0]) ? $arMatches[0][0] : null;
                    $arVars[$name]['type']= 'entity';
                    $arVars[$name]['choice_label']= 'name';


                }


                if($debug){
                    $arVars[$name]['~debug_getDocComment']= $docComments;
                    $arVars[$name]['~debug_ormInfos']= $ormInfos;
                    $arVars[$name]['~debug_assertsParts']= $assertsParts;
                    $arVars[$name]['~debug_getProperty('.$name.')']= (string) $aProp;
                }

//                if(count($arVars[$name])>0){
//                    ksort($arVars[$name], SORT_NATURAL );
//                }
            }
        }

        return $arVars;

    }

    public function getMultiConfigs($FQCN_ClassName,$customConfigAndFields=array(),$customConfigPath="",$debug=false){
        $arConfigs=array();
        // dernière parie du $FQCN_ClassName en minuscule
        $entityName= strtolower( substr($FQCN_ClassName, strrpos($FQCN_ClassName,"\\")+1 ) );
        $path = $this->decorationPath;
        if($customConfigPath==""){
            $customConfigPath=$path;
        }
        $configFile = $customConfigPath."$entityName.yml";
        $globalsFile=  $path."globals.yml";
        $globals=array();
        $config=array();
        $custom=array();
        $arDebug=array();
        $auto=array();

        if(@file_exists($globalsFile)) {
            $arConfigs['globals']= Yaml::parseFile($globalsFile);
        }

        if(@file_exists($configFile)) {
            $arConfigs=array_merge($arConfigs, Yaml::parseFile($configFile));
        }else{
            $arConfigs=array_merge($arConfigs, $this->getAutoConfig($FQCN_ClassName,$debug));
        }

        $owerwrited=array();
        $groups= [0=>'fields',1=>'options'];
        foreach ( array('fields','options') as $aOwerwitableGroup){
            if($aOwerwitableGroup!="")
                if(isset($customConfigAndFields[$aOwerwitableGroup])){
                    if(count($customConfigAndFields[$aOwerwitableGroup])>0){
                        foreach($customConfigAndFields[$aOwerwitableGroup] as $name=>$value){
                            if(isset($arConfigs[$aOwerwitableGroup][$name])){
                                $owerwritePart=0;
                                if(is_array($value)){
                                    $owerwritePrev=array();
                                    foreach ($value as $key=>$Val){
                                        if(isset($arConfigs[$aOwerwitableGroup][$name][$key])) {
                                            $owerwritePart++;
                                            $owerwritePrev[$key]=['prev'=>$arConfigs[$aOwerwitableGroup][$name][$key], 'new'=>$Val];
                                        }
                                        $arConfigs[$aOwerwitableGroup][$name][$key]=$Val;
                                    }
                                    $owerwrited[$aOwerwitableGroup][$name]=$owerwritePrev;
                                }elseif(is_string($value)){
                                    $owerwritePart++;
                                    $arConfigs[$aOwerwitableGroup][$name]=$value;
                                    $owerwrited[$aOwerwitableGroup][$name]=$owerwritePart;
                                }
                            }else{
                                $arConfigs[$aOwerwitableGroup][$name]=$value;
                            }
                        }
                    }
                }

        }

        if($debug!=false){
            $arConfigs['debug']=array(
                'owerwrited'=>$owerwrited,
                'path'=>$path,
                'customConfigPath'=>$customConfigPath,
                'globalsFile'=>$globalsFile,
                'configFile'=>$configFile,
            );
        }

        return $arConfigs;

    }


    /**
     * Affiche la liste des Macros Twig et leur documentation...
     * @return array
     */
    public function _getTwigMacrosListing() {
        $arFinaleDoc=array();
        $template = $this->twig->loadTemplate('@Decorator/macros.html.twig');
//        $variables = $template->getVariables();
//        $arFunc = $template->getBlockNames();

        $arFunc=array();
        $source=$template->getSourceContext()->getCode();
        preg_match_all('/\{\% macro (.*) \%\}/',$source,$arFunc);

//        $arDoc2=array();
//        preg_match_all('/\{# docmacro (.*)\n((.*\n){1,}).*enddocmacro #}\n/',$source,$arDoc2);
//        dump($arDoc2);

        $balStart="docmacro";
        $balStop="enddocmacro";

        foreach ($arFunc[1] as $k=>$find){

            $arDoc=array();
            $find2= strtr($find,array("("=>"\(",")"=>"\)"));
            $pattern='/\{# '.$balStart.' '.$find2.'\n((.*\n){1,}).*'.$balStop.' '.$find2.' #}\n/';
            $res=preg_match_all($pattern,$source,$arDoc);
            $arFunc['doc'][$find]['doc']=isset($arDoc[1][0])?$arDoc[1][0]:"";
            $arFinaleDoc[$find]=isset($arDoc[1][0])?$arDoc[1][0]:"Aucune documentation disponible...";
//            $arFunc['doc'][$find]['find2']=$find2;
//            $arFunc['doc'][$find]['res']=$res;
//            $arFunc['doc'][$find]['pattern']=$pattern;
        }

        ksort($arFinaleDoc, SORT_NATURAL );
        return $arFinaleDoc;

    }


}
?>
