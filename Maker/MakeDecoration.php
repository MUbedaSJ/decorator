<?php

namespace Amu\Bundle\DecoratorBundle\Maker;

//use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Doctrine\DoctrineHelper;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\MakerInterface;
use Symfony\Bundle\MakerBundle\InputAwareMakerInterface;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Bundle\MakerBundle\Doctrine\EntityRegenerator;
use Symfony\Bundle\MakerBundle\FileManager;
use Symfony\Bundle\MakerBundle\Util\ClassSourceManipulator;
use Symfony\Bundle\MakerBundle\Doctrine\EntityRelation;
use Symfony\Bundle\MakerBundle\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Serializer\Encoder\YamlEncoder;
use Symfony\Component\Translation\Dumper\YamlFileDumper;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;
use Symfony\Bundle\MakerBundle\Util\ClassNameDetails;

/**
 * Recherche tous les fichiers templates TWIG,  ainsi que les éventuellements éléments de formulaire présents dans les Controlleurs
 * - ajoute des balises de traduction  {{ "entity.balise_name"|trans }}
 * - créée un fichier de "translation.%locale%.yml" contenant les élément de traductions correspondant
 * * - amélioration des "liens crud" (show, add, edit, delete, back) en ajoutant des classes CSS de Bootstrap (btn...)
 *
 * @author Michel UBÉDA <michel.ubeda@univ-amu.fr>
 *
 * @inspired from MakeEntity (authors: Javier Eguiluz <javier.eguiluz@gmail.com>, Ryan Weaver <weaverryan@gmail.com>, Kévin Dunglas <dunglas@gmail.com>)
 */
final class MakeDecoration extends AbstractMaker implements InputAwareMakerInterface
{
    private $fileManager;
    private $doctrineHelper;
    private $generator;
    /** @var ContainerInterface */
    private $container;

    /** @var string */
    private $yamlExt;

    public function __construct(FileManager $fileManager, DoctrineHelper $doctrineHelper, /* string $projectDirectory,*/
                                Generator $generator = null, ContainerInterface $container)
    {
        $this->yamlExt = "yml";

        $this->fileManager = $fileManager;
        $this->doctrineHelper = $doctrineHelper;
        // $projectDirectory is unused, argument kept for BC

        if (null === $generator) {
            @trigger_error(sprintf('Passing a "%s" instance as 4th argument is mandatory since version 1.5.', Generator::class), E_USER_DEPRECATED);
            $this->generator = new Generator($fileManager, 'App\\');
        } else {
            $this->generator = $generator;
        }

        $this->container = $container;
    }

    public static function getCommandName(): string
    {
        return 'make:decoration';
    }

    /**
     * {@inheritdoc}
     */
    public function configureDependencies(DependencyBuilder $dependencies, InputInterface $input = null)
    {
        // guarantee DoctrineBundle
        $dependencies->addClassDependency(
            DoctrineBundle::class,
            'orm'
        );

        // guarantee ORM
        $dependencies->addClassDependency(
            Column::class,
            'orm'
        );

        $dependencies->addClassDependency(
            Command::class,
            'console'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function configureCommand(Command $command, InputConfiguration $inputConfig)
    {
        $myLocale = $this->getMyLocale();
        $command
            ->setDescription('Generate YAML decorator config files from entities defined fields')
            ->addArgument('entity-class', InputArgument::OPTIONAL, sprintf("Entity name for only one entity analyse (e.g. <fg=yellow>%s</>)", Str::asClassName(Str::getRandomTerm())))
            ->addArgument('path', InputArgument::OPTIONAL, "Define default generation files path (default=\"src\\decorations\")", "src\decorations")
            ->setHelp(<<<EOT

This command <info>%command.name%</info> let you generate YAML decorator config files from entities defined fields
 
  - create/update entities decoration locales files ("src\\decorations\\{%bundle_name%.}%entity_name%.$this->yamlExt")
 
<info>php %command.full_name% Student src\myPath_To_Decoration_Config_fils\</info>

If 'entity-class' is empty, command will ask for it.
(for generating all entities translation use "*" joker).

If 'path' is empty, command will ask for it (default="src\decorations").

EOT
            );
        $inputConfig->setArgumentAsNonInteractive('entity-class');
        $inputConfig->setArgumentAsNonInteractive('path');

    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command)
    {
        if (\PHP_VERSION_ID < 70100) {
            throw new RuntimeCommandException('The make:translation command requires that you use PHP 7.1 or higher.');
        }

        $oneLocale = $input->getArgument('path');
        $myLocale = $this->getMyLocale();

        if ($oneLocale == "") {
            $argument = $command->getDefinition()->getArgument('path');

            $question2 = new Question($argument->getDescription(), $myLocale);
            $question2->setAutocompleterValues(array("src\decorations"));
            $question2->setValidator([Validator::class, 'notBlank']);

            $value = $io->askQuestion($question2);

            $input->setArgument('path', $value);

        }


        $oneEntity = $input->getArgument('entity-class');
        if (($oneEntity == null) || ($oneEntity == "")) {
            $entityFinder = $this->fileManager->createFinder('src')
                ->path("Entity")
                ->name('*.php');
            $classes = [];
            /** @var SplFileInfo $item */
            foreach ($entityFinder as $item) {
                if (!$item->getRelativePathname()) {
                    continue;
                }

                $classes[] = str_replace(['.php', '/'], ['', '\\'], $item->getRelativePathname());
            }

            $argument = $command->getDefinition()->getArgument('entity-class');
            $question = $this->createEntityClassQuestion($argument->getDescription());
            $value = $io->askQuestion($question);

            $input->setArgument('entity-class', $value);

            return;
        } else {
            //
            $entityClassDetails = $this->generator->createClassNameDetails(
                Validator::entityExists($input->getArgument('entity-class'), $this->doctrineHelper->getEntitiesForAutocomplete()),
                'Entity\\'
            );

            //$entityDoctrineDetails = $this->doctrineHelper->createDoctrineDetails($entityClassDetails->getFullName());

        }

    }

    private function getMyLocale()
    {

        $myLocale = $this->container->getParameter('kernel.default_locale');
        if ($myLocale == "") {
            $myLocale = $this->container->getParameter('locale');
        }


        return $myLocale;
    }

    /**
     * Get the bundle name from an Entity
     **/
    private function getBundleNameFromEntity($entityName)
    {
        $bundleName = "";
        foreach ($this->container->get('kernel')->getBundles() as $type => $bundle) {
            $bundleRefClass = new \ReflectionClass($bundle);
            if ($bundleRefClass->getNamespaceName() === $entityName) {
                $bundleName = $type;
            }
        }

        return $bundleName;

    }

    private function createIfNotExistDecorateDirectory($path){
        if (!@file_exists($path)) {
            if (!@mkdir($path)) {
                throw new \RuntimeException('Error on create directory' . $path);
            }
        }
    }
    /**
     * @param ClassNameDetails $entityClassDetails ClassNameDetails
     * @param string $path
     * @param InputInterface $input
     * @param ConsoleStyle $io
     * @param Generator $generator
     */
    private function decorateFile($entityClassDetails, $path, InputInterface $input, ConsoleStyle $io, Generator $generator)
    {
        $class=$entityClassDetails->getFullName();
        if(strpos($class,'App\\AppBundle')!==false){
            $class=str_replace('App\\AppBundle',"AppBundle",$class);
        }

        $class2=str_replace('\\',"\\\\",$class);

        $entityDoctrineDetails = $this->doctrineHelper->createDoctrineDetails($class);
        $entityName = strtolower($entityClassDetails->getShortName());
        $bundleName = $this->getBundleNameFromEntity($class);

        $globalConfig=$this->container->get('decorator_service')->getAutoConfig($class,false);

        $configFile = (($bundleName != "") ? "$bundleName." : "") . "$entityName." . $this->yamlExt;

        $path = str_replace("src\\", $this->container->getParameter('kernel.root_dir')."\\",$path); // '%kernel.project_dir%/src/translations'

        $arYamlTransDatas = array();
        $arYamlTransDatas['entity'] = [
            'name'=>$entityName,
            'class'=>$class,
            'class2'=>$class2,
            'bundle'=>$bundleName
        ];

        //uksort($globalConfig, 'strcasecmp');

        $arYamlTransDatas['fields']=$globalConfig;

        $yamlFile = $path . "/" . $configFile;
        if (@file_exists($yamlFile)) {
            $yamlDatas = Yaml::parseFile($yamlFile);
        } else {
            $yamlDatas = array();
        }


        $nbFound = count($globalConfig);
        $nbUpdate = 0;
        $nbOthers = 0;
        // récupération des valeurs défini dans le fichier de config yml
        if(isset($yamlDatas['fields'])){
            foreach ($yamlDatas['fields'] as $key => $value) {
                if (isset($arYamlTransDatas['fields'][$key])) {
                    $arYamlTransDatas['fields'][$key] = $value;
                    $nbUpdate++;
                } else {
                    $nbOthers++;
                }
            }
        }

        $arYamlTransDatas['collection'] = [
            'handleOnCreate' => "",
            'handleOnDelete' => "",
            'arDefaults' => [],
            'cTotal'=> [],
            'tabClass' => "table table-bordered table-striped table-small",
            'editable' => true,
            'ErrCounter' => '',
        ];

        $arYamlTransDatas['options'] = [
            'title' => "",
            'style' => "",
            'btSize' => "",
            'width' => "100%",
            'align' => "left",
            'visible' => true,
            'showNbRecords' => true,
            'filter' => false,
//            'translation' => true,

            'new' => true,
            'edit' => true,
            'show' => true,
            'delete' => true,
            'back' => true,

            'arActions' => [],
            'arTotals' => [],

            'theme' => "bootstrap_3_horizontal_layout.html.twig",
            'debug' => false,
        ];


        //$rootPath = realpath($this->container->getParameter('kernel.root_dir'));
        //$basePath = realpath($this->container->getParameter('kernel.root_dir').'/..');
        //$path = str_replace('/src','/translations',$rootPath);

        /*

                $arFields = array($entityDoctrineDetails->getIdentifier());
                $arFields = array_merge($arFields, $entityDoctrineDetails->getFormFields());
                foreach ($arFields as $k => $aField) {
                    $vars=null;
                    if(isset($globalConfig[$aField])){
                        $vars=$globalConfig[$aField];

                        //$vars=json_encode($globalConfig[$aField], constant('JSON_OBJECT_AS_ARRAY') + constant('JSON_UNESCAPED_UNICODE') + constant('JSON_PRETTY_PRINT') );
                    }

                    if (isset($arYamlTransDatas['fields'][$aField])) {
                        $nbUpdate++;
                    } else {
                        $nbOthers++;
                    }
                }
                */



//        uksort($arYamlTransDatas, 'strcasecmp');

        $nbTotal = $nbFound + $nbOthers;

        $myYAMLDumpConfig= (   Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE +
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK +
            Yaml::DUMP_OBJECT +
            Yaml::DUMP_OBJECT_AS_MAP
        );
        $formatedYamlText =
            Yaml::dump(array('entity'=>$arYamlTransDatas['entity']),2,4, $myYAMLDumpConfig)."\n".
            Yaml::dump(array("fields"=>$arYamlTransDatas['fields']),3,4, $myYAMLDumpConfig)."\n".
            Yaml::dump(array("options"=>$arYamlTransDatas['options']),5,4, $myYAMLDumpConfig)."\n"
        ;

        if (!@file_exists($yamlFile)) {
            if (!@touch($yamlFile)) {
                throw new \RuntimeException('Error on create file' . $yamlFile .  " (path=$path)");
            }
        }
        if (false === @file_put_contents($yamlFile, $formatedYamlText)) {
            throw new \RuntimeException('Error on writting to ' . $yamlFile);
        }

        $io->text([
            "Working on : <comment>$entityName</comment>' entity",
            "Total: $nbTotal items, $nbUpdate udpated, $nbOthers others",
            '',
        ]);

        return $configFile;
    }

    private function globalsConfigFile( $path, InputInterface $input, ConsoleStyle $io)
    {
        $configFile = "globals." . $this->yamlExt;
        $path = str_replace("src\\", $this->container->getParameter('kernel.root_dir')."/",$path); // '%kernel.project_dir%/src/translations'
        // @todo ajouter config js/css via getParameter(...)
        //$arCss = $this->container->getParameter('decoration.externals.css'));
        //$arJS = $this->container->getParameter('decoration.externals.js'));

        $arCss=[
            '<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">',
            '<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap-theme.min.css" integrity="sha384-rHyoN1iRsVXV4nD0JutlnGaslCJuC7uwjduW9SVrLvRYooPp2bWYgmgJQIXwl/Sp" crossorigin="anonymous">',
            '<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.2.0/css/all.css" integrity="sha384-hWVjflwFxL6sNzntih27bfxkr27PmbbK/iSvJ+a4+0owXq79v+lsFkW54bOGbiDQ" crossorigin="anonymous">',
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">',

            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.css" integrity="sha256-rByPlHULObEjJ6XQxW/flG2r+22R5dKiAoef+aXWfik=" crossorigin="anonymous" />',
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/themes/redmond/jquery-ui.min.css" integrity="sha256-pXjw+x4dOoTZgRBmPD/ilEFccRj2c57rZaYj9A9kRrQ=" crossorigin="anonymous" />',

            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.19/css/dataTables.jqueryui.min.css" integrity="sha256-7sOfBmlxFl2CtSHcz0nHPY7SWhylRQegIp6Zhbht1VQ=" crossorigin="anonymous" />'
        ];

        // '<script src="https://code.jquery.com/jquery-3.3.1.js"></script>',
        $arJS=[
            '<script src="http://code.jquery.com/jquery-1.11.1.min.js"></script>',
            '<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>',
            '<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>',
        ];

        $arDataTable=[
            '<!-- DATATABLE cf https://datatables.net/ -->',
            '<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.19/js/jquery.dataTables.js" integrity="sha256-BFIKaFl5uYR8kP6wcRxaAqJpfZfC424TBccBBVjVzuY=" crossorigin="anonymous"></script>',
            '<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.19/js/dataTables.jqueryui.min.js" integrity="sha256-kWT2I9CDv5SowoYb8rAHuUBouBTE3lUdEpDrauNyQaA=" crossorigin="anonymous"></script>',
            '<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables-fixedheader/2.1.1/dataTables.fixedHeader.min.js" integrity="sha256-KfJFtPOLGCpGuqzclWFBurt6TzJ9domiu3I+NyeNkqk=" crossorigin="anonymous"></script>',
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.19/css/dataTables.jqueryui.min.css" integrity="sha256-7sOfBmlxFl2CtSHcz0nHPY7SWhylRQegIp6Zhbht1VQ=" crossorigin="anonymous" />'
        ];

        $arYamlTransDatas = [
            'theme' => "bootstrap_3_horizontal_layout.html.twig",
            'new' => true,
            'edit' => true,
            'show' => true,
            'delete' => true,
            'style' => "",
            'btSize' => "",
            'width' => "100%",
            'align' => "left",
            'showNbRecords' => true,

            'translation' => true,
            'filter' => false,
            'debug' => false,

            'dateFormat'=>"d/m/Y",
            'datetimeFormat' =>"d/m/Y à H:i",
            'timeFormat'=>"H:i",
            'translation' => true,
            'fixHeader' => false,
            'visible'=>false, // defVisible
            'semiAutomatic'=> "auto", // yaml
            'groupeName'=>"Divers",
            'autoCloseTimeoutFlashMessage'=> 5000,
            'bool' => [
                "" => ['text'=>"Indéterminé", 'icon'=>"glyphicon glyphicon-question-sign text-info"],
                0 => ['text'=>"Désactivé", 'icon'=>"glyphicon glyphicon-remove text-danger"],
                1 => ['text'=>"Activé", 'icon'=>"glyphicon glyphicon-ok text-success"],
            ],
            'buttons' => [
                'add' => ['text' => "Ajouter", 'class' => "btn btn-success", 'icon' => "glyphicon glyphicon-plus"],
                'cancel' => ['text' => "Annuler", 'class' => "btn btn-danger", 'icon' => "glyphicon glyphicon-remove"],
                'delete' => ['text' => "Supprimer", 'class' => "btn btn-danger", 'icon' => "glyphicon glyphicon-trash"],
                'edit' => ['text' => "Éditer", 'class' => "btn btn-primary", 'icon' => "glyphicon glyphicon-pencil"],
                'view' => ['text' => "Visualiser", 'class' => "btn btn-info", 'icon' => "glyphicon glyphicon-search"],
                'update' => ['text' => "Valider", 'class' => "btn btn-success", 'icon' => "glyphicon glyphicon-ok"],
                'back' => ['text' => "Retour", 'class' => "btn btn-info", 'icon' => "glyphicon glyphicon-arrow-left"],
            ],
            'tip'=>[
                'icon'=> "glyphicon glyphicon-question-sign",
                'class'=> "ui-button ui-corner-all",
            ],
            'popover' => [
                'icon'=> "glyphicon glyphicon-info-sign",
                'class'=> "ui-button ui-corner-all",
                'position' => "bottom",
                'trigger' => "hover",
                'minWidth' => "auto",
                'template'=>"<div class='popover' role='tooltip' style='min-width:auto'><div class='arrow'></div><h3 class='popover-title'></h3><div class='popover-content'></div></div>",
            ],
            'css'=>$arCss,
            'js'=>$arJS,
            'dt'=>$arDataTable,
        ];

        $yamlFile = $path . "/" . $configFile;
        if (@file_exists($yamlFile)) {
            $yamlDatas = Yaml::parseFile($yamlFile);
        } else {
            $yamlDatas = array();
        }

        $nbFound = count($arYamlTransDatas);
        $nbUpdate = 0;
        $nbOthers = 0;
        // récupération des valeurs défini dans le fichier de config yml
        if(isset($yamlDatas)){
            foreach ($yamlDatas as $key => $value) {
                if (isset($arYamlTransDatas[$key])) {
                    $arYamlTransDatas[$key] = $value;
                    $nbUpdate++;
                } else {
                    $nbOthers++;
                }
            }
        }

//        uksort($arYamlTransDatas, 'strcasecmp');

        $nbTotal = $nbFound + $nbOthers;

        $myYAMLDumpConfig= (   Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE +
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK +
            Yaml::DUMP_OBJECT +
            Yaml::DUMP_OBJECT_AS_MAP
        );
        $formatedYamlText = Yaml::dump($arYamlTransDatas,5,4, $myYAMLDumpConfig)."\n";

        if (!@file_exists($yamlFile)) {
            if (!@touch($yamlFile)) {
                throw new \RuntimeException('Error on create file' . $yamlFile .  " (path=$path)");
            }
        }
        if (false === @file_put_contents($yamlFile, $formatedYamlText)) {
            throw new \RuntimeException('Error on writting to ' . $yamlFile);
        }

        $io->text([
            "Exporting globals config items : <comment>$configFile</comment>",
            "Total: $nbTotal items, $nbUpdate udpated, $nbOthers others",
            '',
        ]);

        return $configFile;
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator)
    {
        if (\PHP_VERSION_ID < 70100) {
            throw new RuntimeCommandException('The make:entity command requires that you use PHP 7.1 or higher.');
        }

        $entity = $input->getArgument('entity-class');
        $path = $input->getArgument('path', $this->container->getParameter('kernel.root_dir') . "\decorations");

        if ($entity == "*") {
            $arEntities = $this->doctrineHelper->getEntitiesForAutocomplete();
        } else {
            $arEntities = array($entity);
        }

        $this->createIfNotExistDecorateDirectory($path);
        $this->globalsConfigFile($path,  $input, $io);

        /*
        $namespacePrefix='Entity\\';
        $pathToTest=$this->container->getParameter('kernel.root_dir')."/src/AppBundle";
        if(file_exists( $pathToTest)){
            $namespacePrefix='src\\AppBundle\\Entity\\';
        }
        */

        $namespacePrefix='';

        foreach ($arEntities as $oneEntity) {
            $entityClassDetails = $this->generator->createClassNameDetails(
                Validator::entityExists($oneEntity, $this->doctrineHelper->getEntitiesForAutocomplete()),
                $namespacePrefix
            );
            $configFile=$this->decorateFile($entityClassDetails, $path,  $input, $io, $generator);
            $io->text([
                "Using 'entity-class' = <comment>$entity</comment>",
                "Using 'path' = <comment>$path</comment>",
                "Please see result file <info>$configFile</info>",
                '',
            ]);
        }
        $this->writeSuccessMessage($io);
    }


    private function createEntityClassQuestion(string $questionText): Question
    {
        $entityFinder = $this->fileManager->createFinder('src')
            ->path("Entity")
            ->name('*.php');
        $classes = [];
        /** @var SplFileInfo $item */
        foreach ($entityFinder as $item) {
            if (!$item->getRelativePathname()) {
                continue;
            }

            $classes[] = str_replace('/', '\\', str_replace('.php', '', $item->getRelativePathname()));
        }

        $question = new Question($questionText);
        $question->setValidator([Validator::class, 'notBlank']);
        $question->setAutocompleterValues($classes);

        return $question;
    }


}
