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

final class MakeTranslate extends AbstractMaker implements InputAwareMakerInterface
{
    private $fileManager;
    private $doctrineHelper;
    private $generator;
    /** @var ContainerInterface */
    private $container;

    /** @var string  */
    private $yamlExt;

    public function __construct(FileManager $fileManager, DoctrineHelper $doctrineHelper, /* string $projectDirectory,*/ Generator $generator = null,ContainerInterface $container )
    {
        $this->yamlExt="yml";

        $this->fileManager = $fileManager;
        $this->doctrineHelper = $doctrineHelper;
        // $projectDirectory is unused, argument kept for BC

        if (null === $generator) {
            @trigger_error(sprintf('Passing a "%s" instance as 4th argument is mandatory since version 1.5.', Generator::class), E_USER_DEPRECATED);
            $this->generator = new Generator($fileManager, 'App\\');
        } else {
            $this->generator = $generator;
        }

        $this->container= $container;
    }

    public static function getCommandName(): string
    {
        return 'make:translate';
    }

    /**
     * {@inheritdoc}
     */
    public function configureDependencies(DependencyBuilder $dependencies, InputInterface $input = null){
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
        $myLocale=$this->getMyLocale();
        $command
            ->setDescription('Extract YAML translation files from entities defined fields')
            ->addArgument('entity-class', InputArgument::OPTIONAL, sprintf("Entity name for only one entity analyse (e.g. <fg=yellow>%s</>)", Str::asClassName(Str::getRandomTerm())))
            ->addArgument('locale',InputArgument::OPTIONAL,"Limit translate generation file for one locale ('$myLocale' default) or multiples locales (coma separated list, eg: 'es,en,fr')","")
            ->setHelp(<<<EOT

This command <info>%command.name%</info> let you extract YAML translation from entities defined fields
 
  - create/update entities translations locales files ("src\\translations\\%entity_name%.%locale%.$this->yamlExt")
 
<info>php %command.full_name% Student en</info>

If 'entity-class' is empty, command will ask for it.
(for generating all entities translation use "*" joker).

If 'locale' is empty, command will ask for it.

EOT
            );
        $inputConfig->setArgumentAsNonInteractive('entity-class');
        $inputConfig->setArgumentAsNonInteractive('locale');

    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command)
    {
        if (\PHP_VERSION_ID < 70100) {
            throw new RuntimeCommandException('The make:translation command requires that you use PHP 7.1 or higher.');
        }

        $oneLocale= $input->getArgument('locale');
        $myLocale=$this->getMyLocale();

        if($oneLocale==""){
            $argument = $command->getDefinition()->getArgument('locale');

            $question2 = new Question($argument->getDescription(),$myLocale);
            $question2->setAutocompleterValues(array("es","en","fr"));
            $question2->setValidator([Validator::class, 'notBlank']);

            $value = $io->askQuestion($question2);

            $input->setArgument('locale', $value);

        }


        $oneEntity= $input->getArgument('entity-class');
        if(($oneEntity==null) ||($oneEntity=="")){
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
        }else{
            //
            $entityClassDetails = $this->generator->createClassNameDetails(
                Validator::entityExists($input->getArgument('entity-class'), $this->doctrineHelper->getEntitiesForAutocomplete()),
                'Entity\\'
            );

            //$entityDoctrineDetails = $this->doctrineHelper->createDoctrineDetails($entityClassDetails->getFullName());

        }

    }

    private function getMyLocale(){

        $myLocale=$this->container->getParameter('kernel.default_locale');
        if($myLocale==""){
            $myLocale=$this->container->getParameter('locale');
        }


        return $myLocale;
    }

    private function dumpTranslations($entityClassDetails,$locale,InputInterface $input, ConsoleStyle $io, Generator $generator){

        $FQCN=$entityClassDetails->getFullName();
        if(strpos($FQCN,"App\\Entity\\AppBundle\\Entity\\")!==false){
            $FQCN=str_replace("App\\Entity\\","",$FQCN);
        }

        $entityDoctrineDetails = $this->doctrineHelper->createDoctrineDetails($FQCN);
        $entityName=strtolower($entityClassDetails->getShortName());
        /*
        dump($entityClassDetails->getFullName(),
            $entityClassDetails->getRelativeName(),
            $entityClassDetails->getRelativeNameWithoutSuffix()
        );
        */


        $translationFile="$entityName.$locale.".$this->yamlExt;

        $arFields=array($entityDoctrineDetails->getIdentifier());
        $arFields=array_merge($arFields, $entityDoctrineDetails->getFormFields() );


        $arYamlTransDatas=array();
        foreach ($arFields as $k=>$aField){
            $arYamlTransDatas[$entityName.".".$aField ] =  ucfirst($aField);
        }



        //$rootPath = realpath($this->container->getParameter('kernel.root_dir'));
        //$basePath = realpath($this->container->getParameter('kernel.root_dir').'/..');
        //$path = str_replace('/src','/translations',$rootPath);

        $path = str_replace("%kernel.project_dir%",$this->container->getParameter('kernel.root_dir'), $this->container->getParameter("translator.default_path")); // '%kernel.project_dir%/src/translations'
        $yamlFile=$path."/".$translationFile;

        if(@file_exists($yamlFile)) {
            $yamlDatas = Yaml::parseFile($yamlFile);
        }else{
            $yamlDatas=array();
        }

        $nbFound=count($arYamlTransDatas);
        $nbUpdate=0; $nbOthers=0;
        foreach ($yamlDatas as $key=>$value){
            if(isset($arYamlTransDatas[$key])){
                $arYamlTransDatas[$key]= "$value";
                $nbUpdate++;
            }else{
                $arYamlTransDatas[$key]= "$value";
                $nbOthers++;
            }
        }

        uksort($arYamlTransDatas, 'strcasecmp');

        $nbTotal=$nbFound+$nbOthers;
        $formatedYamlText= Yaml::dump($arYamlTransDatas);

        if(!@file_exists($yamlFile)){
            if(!@touch($yamlFile)){
                throw new \RuntimeException('Error on create file: ' . $yamlFile.

                    "\n kernel.root_dir=". $this->container->getParameter('kernel.root_dir').
                    "\n translator.default_path=" .$this->container->getParameter("translator.default_path")

                );
            }
        }
        if (false === @file_put_contents($yamlFile, $formatedYamlText)) {
            throw new \RuntimeException('Error on writting to ' . $yamlFile);
        }

        $io->text([
            "Working on '$entityName' entity : please see result file <comment>$translationFile</comment>",
            "Total: $nbTotal items, $nbUpdate udpated, $nbOthers others",
            '',
        ]);
    }


    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator)
    {
        if (\PHP_VERSION_ID < 70100) {
            throw new RuntimeCommandException('The make:entity command requires that you use PHP 7.1 or higher.');
        }

        $arLocales=array();
        $arEntities=array();

        $entity=$input->getArgument('entity-class');
        //$locale="fr";
        $locale=$input->getArgument('locale','fr');

        if(strpos($locale,',')!==false){
            $arLocales=explode(',',$locale);
        }else{
            $arLocales=array($locale);
        }




        if($entity=="*") {
            $arEntities = $this->doctrineHelper->getEntitiesForAutocomplete();
        }else {
            $arEntities=array($entity);
        }

        foreach ($arEntities as $oneEntity){

            $entityClassDetails = $this->generator->createClassNameDetails(
                Validator::entityExists($oneEntity, $this->doctrineHelper->getEntitiesForAutocomplete()),
                'Entity\\'
            );

            foreach ($arLocales as $oneLocale){

                $io->text([
                    "Using 'entity-class' = <comment>$entity</comment>",
                    "Using 'locale' = <comment>$oneLocale</comment>",
                    '',
                ]);

                $this->dumpTranslations($entityClassDetails,$oneLocale, $input,$io,$generator);

            }

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
