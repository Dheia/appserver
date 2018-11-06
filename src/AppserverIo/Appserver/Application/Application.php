<?php

/**
 * \AppserverIo\Appserver\Application\Application
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 */

namespace AppserverIo\Appserver\Application;

use Rhumsaa\Uuid\Uuid;
use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;
use AppserverIo\Storage\GenericStackable;
use AppserverIo\Appserver\Core\Utilities\LoggerUtils;
use AppserverIo\Appserver\Core\Utilities\DirectoryKeys;
use AppserverIo\Appserver\Core\Traits\ThreadedContextTrait;
use AppserverIo\Psr\ApplicationServer\ContainerInterface;
use AppserverIo\Appserver\Core\Interfaces\ClassLoaderInterface;
use AppserverIo\Appserver\Core\Api\Node\ContextNode;
use AppserverIo\Appserver\Core\Api\Node\LoggerNodeInterface;
use AppserverIo\Appserver\Core\Api\Node\ManagerNodeInterface;
use AppserverIo\Appserver\Core\Api\Node\ClassLoaderNodeInterface;
use AppserverIo\Psr\Application\ManagerInterface;
use AppserverIo\Psr\Application\ApplicationInterface;
use AppserverIo\Psr\Application\ProvisionerInterface;
use AppserverIo\Psr\Application\DirectoryAwareInterface;
use AppserverIo\Psr\Application\FilesystemAwareInterface;
use AppserverIo\Psr\ApplicationServer\ContextInterface;
use AppserverIo\Appserver\Core\Environment;
use AppserverIo\Appserver\Core\Utilities\EnvironmentKeys;
use AppserverIo\Psr\Servlet\SessionUtils;
use AppserverIo\Appserver\Core\Api\ConfigurationService;
use AppserverIo\Appserver\Core\Utilities\SystemPropertyKeys;
use Doctrine\Common\Annotations\AnnotationRegistry;
use AppserverIo\Appserver\Core\Utilities\AppEnvironmentHelper;
use AppserverIo\Provisioning\Configuration\ProvisionerConfigurationInterface;
use AppserverIo\Properties\PropertiesUtil;

/**
 * The application instance holds all information about the deployed application
 * and provides a reference to the servlet manager and the initial context.
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 *
 * @property \AppserverIo\Appserver\Application\ApplicationStateKeys        $applicationState  The application state
 * @property \AppserverIo\Storage\StorageInterface                          $data              Application's data storage
 * @property \AppserverIo\Storage\GenericStackable                          $classLoaders      Stackable holding all class loaders this application has registered
 * @property \AppserverIo\Storage\GenericStackable                          $provisioners      Stackable holding all provisioners this application has registered
 * @property \AppserverIo\Storage\GenericStackable                          $loggers           Stackable holding all loggers this application has registered
 * @property \AppserverIo\Appserver\Application\Interfaces\ContextInterface $initialContext    The initial context instance
 * @property \AppserverIo\Storage\GenericStackable                          $managers          Stackable of managers for this application
 * @property string                                                         $name              Name of the application
 * @property string                                                         $environmentName   Name of the environment the application currently runs in (build.properties)
 * @property string                                                         $serial            The application's UUID
 * @property string                                                         $containerName     Name of the container the application is bound to
 * @property string                                                         $containerRunlevel Runlevel of the container the application is bound to
 * @property \AppserverIo\Psr\Naming\NamingDirectoryInterface               $namingDirectory   The naming directory instance
 * @property \AppserverIo\Appserver\Core\Api\Node\ContextNode               $contextNode       The application configuration instance
 */
class Application extends \Thread implements ApplicationInterface, DirectoryAwareInterface, FilesystemAwareInterface, \AppserverIo\Psr\Context\ContextInterface
{

    /**
     * The time we wait after each loop.
     *
     * @var integer
     */
    const TIME_TO_LIVE = 1;

    /**
     * Trait that provides threaded context functionality.
     *
     * @var \AppserverIo\Appserver\Core\Traits\ThreadedContextTrait
     */
    use ThreadedContextTrait;

    /**
     * Initialize the internal members.
     */
    public function __construct()
    {

        // create a UUID as prefix for dynamic object properties
        $this->serial = Uuid::uuid4()->toString();

        // initialize the application state
        $this->applicationState = ApplicationStateKeys::get(ApplicationStateKeys::WAITING_FOR_INITIALIZATION);
    }

    /**
     * Inject the environment name
     *
     * @param string $environmentName The environment name to inject
     *
     * @return void
     */
    public function injectEnvironmentName($environmentName)
    {
        $this->environmentName = $environmentName;
    }

    /**
     * Injects the naming directory.
     *
     * @param \AppserverIo\Psr\Naming\NamingDirectoryInterface $namingDirectory The naming directory instance
     *
     * @return void
     */
    public function injectNamingDirectory($namingDirectory)
    {
        $this->namingDirectory = $namingDirectory;
    }

    /**
     * Injects the storage for the managers.
     *
     * @param \AppserverIo\Storage\GenericStackable $managers The storage for the managers
     *
     * @return void
     */
    public function injectManagers(GenericStackable $managers)
    {
        $this->managers = $managers;
    }

    /**
     * Injects the storage for the class loaders.
     *
     * @param \AppserverIo\Storage\GenericStackable $classLoaders The storage for the class loaders
     *
     * @return void
     */
    public function injectClassLoaders(GenericStackable $classLoaders)
    {
        $this->classLoaders = $classLoaders;
    }

    /**
     * Injects the storage for the provisioners.
     *
     * @param \AppserverIo\Storage\GenericStackable $provisioners The storage for the provisioners
     *
     * @return void
     */
    public function injectProvisioners(GenericStackable $provisioners)
    {
        $this->provisioners = $provisioners;
    }

    /**
     * Injects the storage for the loggers.
     *
     * @param \AppserverIo\Storage\GenericStackable $loggers The storage for the loggers
     *
     * @return void
     */
    public function injectLoggers(GenericStackable $loggers)
    {
        $this->loggers = $loggers;
    }

    /**
     * The initial context instance.
     *
     * @param \AppserverIo\Psr\ApplicationServer\ContextInterface $initialContext The initial context instance
     *
     * @return void
     */
    public function injectInitialContext(ContextInterface $initialContext)
    {
        $this->initialContext = $initialContext;
    }

    /**
     * Injects the application name.
     *
     * @param string $name The application name
     *
     * @return void
     */
    public function injectName($name)
    {
        $this->name = $name;
    }

    /**
     * Returns the application name (that has to be the class namespace, e.g. example)
     *
     * @return string The application name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns the applications environment name
     *
     * @return string The applications environment name
     */
    public function getEnvironmentName()
    {
        return $this->environmentName;
    }

    /**
     * Injects the name of the container the application is bound to.
     *
     * @param string $containerName The container's name
     *
     * @return void
     */
    public function injectContainerName($containerName)
    {
        $this->containerName = $containerName;
    }

    /**
     * Returns the name of the container the application is bound to.
     *
     * @return string The container's name
     */
    public function getContainerName()
    {
        return $this->containerName;
    }

    /**
     * Injects the runlevel of the container the application is bound to.
     *
     * @param string $containerRunlevel The container's runlevel
     *
     * @return void
     */
    public function injectContainerRunlevel($containerRunlevel)
    {
        $this->containerRunlevel = $containerRunlevel;
    }

    /**
     * Returns the runlevel of the container the application is bound to.
     *
     * @return string The container's runlevel
     */
    public function getContainerRunlevel()
    {
        return $this->containerRunlevel;
    }

    /**
     * Returns the applications naming directory.
     *
     * @return \AppserverIo\Psr\Naming\NamingDirectoryInterface The applications naming directory interface
     */
    public function getNamingDirectory()
    {
        return $this->namingDirectory;
    }

    /**
     * Return's the application configuration.
     *
     * @return \AppserverIo\Appserver\Core\Api\Node\ContextNode The application configuration
     */
    public function getContextNode()
    {
        return $this->contextNode;
    }

    /**
     * Returns the absolute path to the servers document root directory
     *
     * @param string $directoryToAppend The directory to append to the base directory
     *
     * @return string The base directory with appended dir if given
     */
    public function getBaseDirectory($directoryToAppend = null)
    {
        $baseDirectory = $this->getNamingDirectory()->search('php:env/baseDirectory');
        if ($directoryToAppend != null) {
            $baseDirectory .= $directoryToAppend;
        }
        return $baseDirectory;
    }

    /**
     * Returns the absolute path to the applications base directory.
     *
     * @return string The app base directory
     */
    public function getAppBase()
    {
        return $this->getNamingDirectory()->search(sprintf('php:env/%s/appBase', $this->getUniqueName()));
    }

    /**
     * Returns the absolute path to the web application base directory.
     *
     * @return string The path to the webapps folder
     */
    public function getWebappPath()
    {
        return $this->getNamingDirectory()->search(sprintf('php:env/%s/webappPath', $this->getUniqueName()));
    }

    /**
     * Returns the absolute path to the applications temporary directory.
     *
     * @return string The app temporary directory
     */
    public function getTmpDir()
    {
        return $this->getNamingDirectory()->search(sprintf('php:env/%s/tmpDirectory', $this->getUniqueName()));
    }

    /**
     * Returns the absolute path to the applications data directory.
     *
     * @return string The app data directory
     */
    public function getDataDir()
    {
        return $this->getNamingDirectory()->search(sprintf('php:env/%s/dataDirectory', $this->getUniqueName()));
    }

    /**
     * Returns the absolute path to the applications session directory.
     *
     * @return string The app session directory
     */
    public function getSessionDir()
    {
        return $this->getNamingDirectory()->search(sprintf('php:env/%s/sessionDirectory', $this->getUniqueName()));
    }

    /**
     * Returns the absolute path to the applications cache directory.
     *
     * @return string The app cache directory
     */
    public function getCacheDir()
    {
        return $this->getNamingDirectory()->search(sprintf('php:env/%s/cacheDirectory', $this->getUniqueName()));
    }

    /**
     * Returns the username the application should be executed with.
     *
     * @return string The username
     */
    public function getUser()
    {
        return $this->getNamingDirectory()->search('php:env/user');
    }

    /**
     * Returns the groupname the application should be executed with.
     *
     * @return string The groupname
     */
    public function getGroup()
    {
        return $this->getNamingDirectory()->search('php:env/group');
    }

    /**
     * Returns the umask the application should create files/directories with.
     *
     * @return string The umask
     */
    public function getUmask()
    {
        return $this->getNamingDirectory()->search('php:env/umask');
    }

    /**
     * Return's the container instance the application is bound to.
     *
     * @return \AppserverIo\Psr\ApplicationServer\ContainerInterface The container instance
     */
    public function getContainer()
    {
        return $this->getNamingDirectory()->search(sprintf('php:services/%s/%s', $this->getContainerRunlevel(), $this->getContainerName()));
    }

    /**
     * Return's the system properties enriched with the application specific properties like webapp.dir etc.
     *
     * @return \AppserverIo\Properties\PropertiesInterface The sytem properties
     */
    public function getSystemProperties()
    {

        // load the configuration service
        $service = $this->newService(ConfigurationService::class);

        // load the system properties
        $systemProperties =  $service->getSystemProperties($this->getContainer()->getContainerNode());

        // append the application specific properties
        $systemProperties->add(SystemPropertyKeys::WEBAPP, $webappPath = $this->getWebappPath());
        $systemProperties->add(SystemPropertyKeys::WEBAPP_NAME, basename($webappPath));
        $systemProperties->add(SystemPropertyKeys::WEBAPP_DATA, $this->getDataDir());
        $systemProperties->add(SystemPropertyKeys::WEBAPP_CACHE, $this->getCacheDir());
        $systemProperties->add(SystemPropertyKeys::WEBAPP_SESSION, $this->getSessionDir());

        // return the system properties
        return $systemProperties;
    }

    /**
     * Replaces the variablies in the passed file.
     *
     * @param string $pathname The filename with the variables that has to be replaced
     *
     * @return string The content of the file with the replaced variables
     */
    public function replaceSystemProperties($pathname)
    {
        return PropertiesUtil::singleton()->replacePropertiesInString($this->getSystemProperties(), file_get_contents($pathname));
    }

    /**
     * Return's the application's UUID.
     *
     * @return string The application's UUID
     */
    public function getSerial()
    {
        return $this->serial;
    }

    /**
     * Return's the unique application name that is the container + application name
     * separated with a slash, e. g. combined-appserver/example.
     *
     * @return string
     */
    public function getUniqueName()
    {
        return sprintf('%s/%s', $this->getContainerName(), $this->getName());
    }

    /**
     * (non-PHPdoc)
     *
     * @param string $className The API service class name to return the instance for
     *
     * @return object The service instance
     * @see \AppserverIo\Psr\ApplicationServer\ContextInterface::newService()
     */
    public function newService($className)
    {
        return $this->getInitialContext()->newService($className);
    }

    /**
     * Returns the initial context instance.
     *
     * @return \AppserverIo\Psr\ApplicationServer\ContextInterface The initial Context
     */
    public function getInitialContext()
    {
        return $this->initialContext;
    }

    /**
     * Return the requested class loader instance
     *
     * @param string $identifier The unique identifier of the requested class loader
     *
     * @return \AppserverIo\Appserver\Core\Interfaces\ClassLoaderInterface The class loader instance
     */
    public function getClassLoader($identifier)
    {
        if (isset($this->classLoaders[$identifier])) {
            return $this->classLoaders[$identifier];
        }
    }

    /**
     * Return the class loaders.
     *
     * @return \AppserverIo\Storage\GenericStackable The class loader instances
     */
    public function getClassLoaders()
    {
        return $this->classLoaders;
    }

    /**
     * Returns the manager instances.
     *
     * @return \AppserverIo\Storage\GenericStackable The manager instances
     */
    public function getManagers()
    {
        return $this->managers;
    }

    /**
     * Return the requested manager instance.
     *
     * @param string $identifier The unique identifier of the requested manager
     *
     * @return \AppserverIo\Psr\Application\ManagerInterface The manager instance
     */
    public function getManager($identifier)
    {
        if (isset($this->managers[$identifier])) {
            return $this->managers[$identifier];
        }
    }

    /**
     * Returns the provisioner instances.
     *
     * @return \AppserverIo\Storage\GenericStackable The provisioner instances
     */
    public function getProvisioners()
    {
        return $this->provisioners;
    }

    /**
     * Return the requested provisioner instance.
     *
     * @param string $identifier The unique identifier of the requested provisioner
     *
     * @return \AppserverIo\Psr\Application\ProvisionerInterface The provisioner instance
     */
    public function getProvisioner($identifier)
    {
        if (isset($this->provisioners[$identifier])) {
            return $this->provisioners[$identifier];
        }
    }

    /**
     * Returns the logger instances.
     *
     * @return \AppserverIo\Storage\GenericStackable The logger instances
     */
    public function getLoggers()
    {
        return $this->loggers;
    }

    /**
     * Return the requested logger instance, by default the application's system logger.
     *
     * @param string $name The name of the requested logger
     *
     * @return \Psr\Log\LoggerInterface|null The logger instance
     */
    public function getLogger($name = LoggerUtils::SYSTEM_LOGGER)
    {
        if (isset($this->loggers[$name])) {
            return $this->loggers[$name];
        }
        return null;
    }

    /**
     * Injects an additional class loader.
     *
     * @param \AppserverIo\Appserver\Core\Interfaces\ClassLoaderInterface   $classLoader   A class loader to put on the class loader stack
     * @param \AppserverIo\Appserver\Core\Api\Node\ClassLoaderNodeInterface $configuration The class loader's configuration
     *
     * @return void
     */
    public function addClassLoader(ClassLoaderInterface $classLoader, ClassLoaderNodeInterface $configuration)
    {

        // bind the class loader callback to the naming directory => the application itself
        $this->getNamingDirectory()->bind(sprintf('php:global/%s/%s', $this->getUniqueName(), $configuration->getName()), array(&$this, 'getClassLoader'), array($configuration->getName()));

        // add the class loader instance to the application
        $this->classLoaders[$configuration->getName()] = $classLoader;
    }

    /**
     * Injects manager instance and the configuration.
     *
     * @param \AppserverIo\Psr\Application\ManagerInterface             $manager       A manager instance
     * @param \AppserverIo\Appserver\Core\Api\Node\ManagerNodeInterface $configuration The managers configuration
     *
     * @return void
     */
    public function addManager(ManagerInterface $manager, ManagerNodeInterface $configuration)
    {

        // bind the manager callback to the naming directory => the application itself
        $this->getNamingDirectory()->bind(sprintf('php:global/%s/%s', $this->getUniqueName(), $configuration->getName()), array(&$this, 'getManager'), array($configuration->getName()));

        // add the manager instance to the application
        $this->managers[$configuration->getName()] = $manager;
    }

    /**
     * Injects the provisioner instance and the configuration.
     *
     * @param \AppserverIo\Psr\Application\ProvisionerInterface                         $provisioner   A provisioner instance
     * @param \AppserverIo\Provisioning\Configuration\ProvisionerConfigurationInterface $configuration The provisioner configuration
     *
     * @return void
     */
    public function addProvisioner(ProvisionerInterface $provisioner, ProvisionerConfigurationInterface $configuration)
    {

        // bind the provisioner callback to the naming directory => the application itself
        $this->getNamingDirectory()->bind(sprintf('php:global/%s/%s', $this->getUniqueName(), $configuration->getName()), array(&$this, 'getProvisioner'), array($configuration->getName()));

        // add the provisioner instance to the application
        $this->provisioners[$configuration->getName()] = $provisioner;
    }

    /**
     * Injects the logger instance and the configuration.
     *
     * @param \Psr\Log\LoggerInterface                                 $logger        A provisioner instance
     * @param \AppserverIo\Appserver\Core\Api\Node\LoggerNodeInterface $configuration The provisioner configuration
     *
     * @return void
     */
    public function addLogger(LoggerInterface $logger, LoggerNodeInterface $configuration)
    {

        // bind the logger callback to the naming directory => the application itself
        $this->getNamingDirectory()->bind($name = sprintf('php:global/log/%s/%s', $this->getUniqueName(), $configuration->getName()), array(&$this, 'getLogger'), array($configuration->getName()));

        // alos bind a reference from the application to the logger scope (to make DI more comfortable)
        $this->getNamingDirectory()->bindReference(sprintf('php:global/%s/%s', $this->getUniqueName(), $configuration->getName()), $name);

        // add the logger instance to the application
        $this->loggers[$configuration->getName()] = $logger;
    }

    /**
     * Prepares the application with the specific data found in the
     * passed context node.
     *
     * @param \AppserverIo\Psr\ApplicationServer\ContainerInterface $container   The container instance bind the application to
     * @param \AppserverIo\Appserver\Core\Api\Node\ContextNode      $contextNode The application configuration
     *
     * @return void
     */
    public function prepare(ContainerInterface $container, ContextNode $contextNode)
    {

        // set the application configuration
        $this->contextNode = $contextNode;

        // load the unique application name + the naming directory
        $uniqueName = $this->getUniqueName();
        $namingDirectory = $this->getNamingDirectory();

        // create subdirectories for the application and the logger
        $namingDirectory->createSubdirectory(sprintf('php:global/%s', $uniqueName));
        $namingDirectory->createSubdirectory(sprintf('php:global/log/%s', $uniqueName));

        // create the applications 'env' + 'env/persistence' directory the beans + persistence units will be bound to
        $namingDirectory->createSubdirectory(sprintf('php:env/%s', $uniqueName));
        $namingDirectory->createSubdirectory(sprintf('php:global/%s/env', $uniqueName));
        $namingDirectory->createSubdirectory(sprintf('php:global/%s/env/persistence', $uniqueName));

        // bind the directory containing the applications
        $namingDirectory->bind(sprintf('php:env/%s/appBase', $uniqueName), $container->getAppBase());

        // prepare the application specific directories
        $webappPath = sprintf('%s/%s', $this->getAppBase(), $this->getName());
        $tmpDirectory = sprintf('%s/%s', $container->getTmpDir(), $this->getName());
        $dataDirectory = sprintf('%s/%s', $tmpDirectory, ltrim($contextNode->getParam(DirectoryKeys::DATA), '/'));
        $cacheDirectory = sprintf('%s/%s', $tmpDirectory, ltrim($contextNode->getParam(DirectoryKeys::CACHE), '/'));
        $sessionDirectory = sprintf('%s/%s', $tmpDirectory, ltrim($contextNode->getParam(DirectoryKeys::SESSION), '/'));

        // prepare the application specific environment variables
        $namingDirectory->bind(sprintf('php:env/%s/webappPath', $uniqueName), $webappPath);
        $namingDirectory->bind(sprintf('php:env/%s/tmpDirectory', $uniqueName), $tmpDirectory);
        $namingDirectory->bind(sprintf('php:env/%s/dataDirectory', $uniqueName), $dataDirectory);
        $namingDirectory->bind(sprintf('php:env/%s/cacheDirectory', $uniqueName), $cacheDirectory);
        $namingDirectory->bind(sprintf('php:env/%s/sessionDirectory', $uniqueName), $sessionDirectory);

        // bind the interface as reference to the application
        $namingDirectory->bind($uri = sprintf('php:global/%s/%s', $uniqueName, ApplicationInterface::IDENTIFIER), $this);

        // also bind an alias to the application
        /** @deprecated Also bind an alias to the application to ensure backwards compatibility */
        $namingDirectory->bindReference(sprintf('php:global/%s/Application', $uniqueName), $uri);
    }

    /**
     * Will take a segmented path to a file (which might contain glob type wildcards) and return it fixed to the currently
     * active environment modifier, e. g.
     *
     * ```php
     * AppEnvironmentHelper::getEnvironmentAwareFilePath('webapps/example', 'META-INF/*-ds') => 'webapps/example/META-INF/*-ds.dev.xml'
     * ```
     *
     * @param string  $fileGlob      The intermediate path (or glob pattern) from app base path to file extension
     * @param integer $flags         The flags passed to the glob function
     * @param string  $fileExtension The extension of the file, will default to 'xml'
     *
     * @return string
     * @see \AppserverIo\Appserver\Core\Utilities\AppEnvironmentHelper::getEnvironmentAwareGlobPattern()
     */
    public function getEnvironmentAwareGlobPattern($fileGlob, $flags = 0, $fileExtension = 'xml')
    {
        return AppEnvironmentHelper::getEnvironmentAwareGlobPattern($this->getWebappPath(), $fileGlob, $flags, $fileExtension);
    }

    /**
     * Cleanup the naming directory from the application entries.
     *
     * @return void
     */
    public function unload()
    {

        // load the unique application name + the naming directory
        $uniqueName = $this->getUniqueName();
        $namingDirectory = $this->getNamingDirectory();

        // unbind the environment references of the application
        $namingDirectory->unbind(sprintf('php:env/%s/webappPath', $uniqueName));
        $namingDirectory->unbind(sprintf('php:env/%s/tmpDirectory', $uniqueName));
        $namingDirectory->unbind(sprintf('php:env/%s/dataDirectory', $uniqueName));
        $namingDirectory->unbind(sprintf('php:env/%s/cacheDirectory', $uniqueName));
        $namingDirectory->unbind(sprintf('php:env/%s/sessionDirectory', $uniqueName));
        $namingDirectory->unbind(sprintf('php:env/%s', $uniqueName));

        // unbind the global references of the application
        $namingDirectory->unbind(sprintf('php:global/%s/env/ApplicationInterface', $uniqueName));
        $namingDirectory->unbind(sprintf('php:global/%s/env/persistence', $uniqueName));
        $namingDirectory->unbind(sprintf('php:global/%s/env', $uniqueName));
        $namingDirectory->unbind(sprintf('php:global/%s', $uniqueName));
    }

    /**
     * Has been automatically invoked by the container after the application
     * instance has been created.
     *
     * @return void
     * @see \Thread::run()
     * @codeCoverageIgnore
     */
    public function connect()
    {
        $this->start();
    }

    /**
     * TRUE if the application has been connected, else FALSE.
     *
     * @return boolean Returns TRUE if the application has been connected, else FALSE
     */
    public function isConnected()
    {
        return $this->synchronized(function ($self) {
            return $self->applicationState->equals(ApplicationStateKeys::get(ApplicationStateKeys::INITIALIZATION_SUCCESSFUL));
        }, $this);
    }

    /**
     * Registers all class loaders injected to the applications in the opposite
     * order as they have been injected.
     *
     * @return void
     */
    public function registerClassLoaders()
    {

        // initialize the registered managers
        /** @var \AppserverIo\Appserver\Core\Interfaces\ClassLoaderInterface $classLoader */
        foreach ($this->getClassLoaders() as $classLoader) {
            // log the class loader we want to initialize
            $this->getInitialContext()->getSystemLogger()->debug(
                sprintf('Now register classloader %s for application %s', get_class($classLoader), $this->getName())
            );

            // register the class loader instance
            $classLoader->register(true, true);

            // log the class loader we've successfully registered
            $this->getInitialContext()->getSystemLogger()->debug(
                sprintf('Successfully registered classloader %s for application %s', get_class($classLoader), $this->getName())
            );
        }
    }

    /**
     * Registers additional annotation registries defined in the configuration.
     *
     * @return void
     */
    public function registerAnnotationRegistries()
    {

        // reset the annotation registry
        AnnotationRegistry::reset();

        // register additional annotation libraries
        foreach ($this->getContextNode()->getAnnotationRegistries() as $annotationRegistry) {
            // register the annotations specified by the annotation registery
            $annotationRegistryType = $annotationRegistry->getType();
            $registry = new $annotationRegistryType();
            $registry->register($annotationRegistry);
        }
    }

    /**
     * Registers the the application in the environment.
     *
     * @return void
     */
    public function registerEnvironment()
    {

        // add the application instance to the environment
        Environment::singleton()->setAttribute(EnvironmentKeys::APPLICATION, $this);

        // create s simulated request/session ID whereas session equals request ID
        Environment::singleton()->setAttribute(EnvironmentKeys::SESSION_ID, $sessionId = SessionUtils::generateRandomString());
        Environment::singleton()->setAttribute(EnvironmentKeys::REQUEST_ID, $sessionId);
    }

    /**
     * Provisions the initialized application.
     *
     * @return void
     */
    public function provision()
    {

        // invoke the provisioners and provision the application
        /** @var \AppserverIo\Psr\Application\ProvisionerInterface $provisioner */
        foreach ($this->getProvisioners() as $provisioner) {
            // log the manager we want to initialize
            \debug(sprintf('Now invoking provisioner %s for application %s', get_class($provisioner), $this->getName()));

            // execute the provisioning steps
            $provisioner->provision($this);

            // log the manager we've successfully registered
            \debug(sprintf('Successfully invoked provisioner %s for application %s', get_class($provisioner), $this->getName()));
        }
    }

    /**
     * Registers all managers in the application.
     *
     * @return void
     */
    public function initializeManagers()
    {

        // initialize the registered managers
        /** @var \AppserverIo\Psr\Application\ManagerInterface $manager */
        foreach ($this->getManagers() as $manager) {
            // log the manager we want to initialize
            \debug(sprintf('Now register manager %s for application %s', get_class($manager), $this->getName()));

            // initialize the manager instance
            $manager->initialize($this);

            // log the manager we've successfully registered
            \debug(sprintf('Now registered manager %s for application %s', get_class($manager), $this->getName()));
        }
    }

    /**
     * Invokes the postStartup() method lifecycle callback of the registered managers.
     *
     * @return void
     */
    public function postStartupManagers()
    {

        // initialize the registered managers
        /** @var \AppserverIo\Psr\Application\ManagerInterface $manager */
        foreach ($this->getManagers() as $manager) {
            // log the manager we want to invoke the postStartup() lifecycle callback
            \debug(sprintf('Now invoke the postStartup() lifecycle callback of manager %s for application %s', get_class($manager), $this->getName()));

            // invoke the manager's postStartup() lifecycle callback
            $manager->postStartup($this);

            // log the manager we've successfully invoked the postStartup() lifecycle callback
            \debug(sprintf('Successfully invoked the postStartup() lifecycle callback of manager %s for application %s', get_class($manager), $this->getName()));
        }
    }

    /**
     * Stops the application instance.
     *
     * @return void
     */
    public function stop()
    {

        // start application shutdown
        $this->synchronized(function ($self) {
            $self->applicationState = ApplicationStateKeys::get(ApplicationStateKeys::HALT);
        }, $this);

        do {
            // log a message that we'll wait till application has been shutdown
            \info(sprintf('Wait for application %s to be shutdown', $this->getName()));

            // query whether application state key is SHUTDOWN or not
            $waitForShutdown = $this->synchronized(function ($self) {
                return $self->applicationState->notEquals(ApplicationStateKeys::get(ApplicationStateKeys::SHUTDOWN));
            }, $this);

            // wait one second more
            sleep(1);

        } while ($waitForShutdown);
    }

    /**
     * Queries the naming directory for the requested name and returns the value
     * or invokes the bound callback.
     *
     * @param string $name The name of the requested value
     * @param array  $args The arguments to pass to the callback
     *
     * @return mixed The requested value
     * @see \AppserverIo\Appserver\Naming\NamingDirectory::search()
     */
    public function search($name, array $args = array())
    {
        return $this->getNamingDirectory()->search(sprintf('php:global/%s/%s', $this->getUniqueName(), $name), $args);
    }

    /**
     * This is the threads main() method that initializes the application with the autoloader and
     * instantiates all the necessary manager instances.
     *
     * @return void
     * @codeCoverageIgnore
     */
    public function run()
    {

        try {
            // register the default autoloader
            require SERVER_AUTOLOADER;

            // register shutdown handler
            register_shutdown_function(array(&$this, "shutdown"));

            // add the application instance to the environment
            Environment::singleton()->setAttribute(EnvironmentKeys::APPLICATION, $this);

            // create s simulated request/session ID whereas session equals request ID
            Environment::singleton()->setAttribute(EnvironmentKeys::SESSION_ID, $sessionId = SessionUtils::generateRandomString());
            Environment::singleton()->setAttribute(EnvironmentKeys::REQUEST_ID, $sessionId);

            // log a message that we now start to connect the application
            $this->getInitialContext()->getSystemLogger()->debug(sprintf('%s wait to be connected', $this->getName()));

            // register the class loaders
            $this->registerClassLoaders();

            // register the annotation registries
            $this->registerAnnotationRegistries();

            // initialize the managers
            $this->initializeManagers();

            // provision the application
            if ($this->getContainer()->hasProvisioningEnabled()) {
                $this->provision();
            }

            // initialize the profile logger and the thread context
            $profileLogger = null;
            /** @var \AppserverIo\Logger\ThreadSafeLoggerInterface $profileLogger */
            if ($profileLogger = $this->getInitialContext()->getLogger(LoggerUtils::PROFILE)) {
                $profileLogger->appendThreadContext('application');
            }

            // the application has successfully been initialized
            $this->synchronized(function ($self) {
                $self->applicationState = ApplicationStateKeys::get(ApplicationStateKeys::INITIALIZATION_SUCCESSFUL);
            }, $this);

            // log a message that we has successfully been connected now
            \info(sprintf('%s has successfully been connected', $this->getName()));

            // invoke the application's managers postStartup() lifecycle callbacks
            $this->postStartupManagers();

            // initialize the flag to keep the application running
            $keepRunning = true;

            // wait till application will be shutdown
            while ($keepRunning) {
                // query whether we've a profile logger, log resource usage
                if ($profileLogger) {
                    $profileLogger->debug(sprintf('Application %s is running', $this->getName()));
                }

                // wait a second to lower system load
                $keepRunning = $this->synchronized(function ($self) {
                    $self->wait(100000 * Application::TIME_TO_LIVE);
                    return $self->applicationState->equals(ApplicationStateKeys::get(ApplicationStateKeys::INITIALIZATION_SUCCESSFUL));
                }, $this);
            }

            // log a message that we has successfully been shutdown now
            \info(sprintf('%s start to shutdown managers', $this->getName()));

            // array for the manager shutdown threads
            $shutdownThreads = array();

            // we need to stop all managers, because they've probably running threads
            /** @var \AppserverIo\Psr\Application\ManagerInterface $manager */
            foreach ($this->getManagers() as $manager) {
                $shutdownThreads[] = new ManagerShutdownThread($manager);
            }

            // wait till all managers have been shutdown
            /** @var \AppserverIo\Appserver\Application\ManagerShutdownThread $shutdownThread */
            foreach ($shutdownThreads as $shutdownThread) {
                $shutdownThread->join();
            }

            // the application has been shutdown successfully
            $this->synchronized(function ($self) {
                $self->applicationState = ApplicationStateKeys::get(ApplicationStateKeys::SHUTDOWN);
            }, $this);

            // cleanup the naming directory with the application entries
            $this->unload();

            // log a message that we has successfully been shutdown now
            \info(sprintf('%s has successfully been shutdown', $this->getName()));

        } catch (\Exception $e) {
            LoggerUtils::log(LogLevel::ERROR, $e);
        }
    }

    /**
     * Shutdown function to log unexpected errors.
     *
     * @return void
     * @see http://php.net/register_shutdown_function
     */
    public function shutdown()
    {

        // check if there was a fatal error caused shutdown
        if ($lastError = error_get_last()) {
            // initialize error type and message
            $type = 0;
            $message = '';
            // extract the last error values
            extract($lastError);
            // query whether we've a fatal/user error
            if ($type === E_ERROR || $type === E_USER_ERROR) {
                LoggerUtils::log(LogLevel::CRITICAL, $message);
            }
        }
    }
}
