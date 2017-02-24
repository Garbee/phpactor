<?php

namespace Phpactor\Extension;

use Composer\Autoload\ClassLoader;
use PhpBench\DependencyInjection\ExtensionInterface;
use PhpBench\DependencyInjection\Container;
use Symfony\Component\Console\Application;
use Phpactor\Console\Command\ExplainCommand;
use Phpactor\Reflection\ComposerReflector;
use Doctrine\DBAL\Connection;
use Phpactor\Storage\Storage;
use Phpactor\Storage\ConnectionWrapper;
use Doctrine\DBAL\DriverManager;
use Phpactor\Console\Command\CompleteCommand;
use Phpactor\Complete\Completer;
use Phpactor\Complete\Provider\VariableProvider;
use BetterReflection\Reflector\ClassReflector;
use Phpactor\Complete\Provider\FetchProvider;
use Phpactor\Console\Command\GenerateSnippetCommand;
use Phpactor\Util\ClassUtil;
use Phpactor\Generation\Snippet\MissingMethodsGenerator;
use Phpactor\Generation\SnippetGeneratorRegistry;
use Phpactor\Generation\Snippet\ImplementMissingMethodsGenerator;
use Phpactor\Generation\Snippet\MissingPropertiesGenerator;
use Phpactor\Generation\Snippet\ClassGenerator;
use Phpactor\Generation\SnippetCreator;
use Phpactor\Composer\ClassNameResolver;
use DTL\WorseReflection\SourceLocator\StringSourceLocator;
use DTL\WorseReflection\Reflector;
use PhpParser\ParserFactory;
use PhpParser\Lexer;
use DTL\WorseReflection\SourceContextFactory;
use DTL\WorseReflection\Source;
use DTL\WorseReflection\SourceLocator\ComposerSourceLocator;

class CoreExtension implements ExtensionInterface
{
    const APP_NAME = 'phpactor';
    const APP_VERSION = '0.1.0';

    public function getDefaultConfig()
    {
        return [
            'db.path' => getcwd() . '/phpactor.sqlite',
            'autoload' => 'vendor/autoload.php',
            'source' => null,
        ];
    }

    public function load(Container $container)
    {
        $this->registerComplete($container);
        $this->registerGeneration($container);
        $this->registerConsole($container);
        $this->registerStorage($container);
        $this->registerMisc($container);
        $this->registerComposer($container);
    }

    private function registerComplete(Container $container)
    {
        $container->register('completer.provider.variables', function ($container) {
            return new VariableProvider($container->get('reflector'));
        });
        $container->register('completer.provider.property_fetch', function ($container) {
            return new FetchProvider($container->get('reflector'));
        });
        $container->register('completer', function (Container $container) {
            return new Completer(
                $container->get('reflector'), [
                    $container->get('completer.provider.variables'),
                    $container->get('completer.provider.property_fetch')
                ]
            );
        });
    }

    private function registerStorage(Container $container)
    {
        $container->register('dbal.connection_wrapper', function (Container $container) {
            return new ConnectionWrapper($container->get('dbal.connection'));
        });

        $container->register('dbal.connection', function (Container $container) {
            return DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $container->getParameter('db.path')
            ]);
        });
        $container->register('storage', function (Container $container) {
            return new Storage(
                $container->get('dbal.connection_wrapper')->getConnection()
            );
        });
    }

    private function registerMisc(Container $container)
    {
        $container->register('php_parser', function (Container $container) {
            $lexer = new Lexer([
                'usedAttributes' => [
                    'comments',
                    'startLine',
                    'endLine',
                    'startFilePos',
                    'endFilePos'
                ]
            ]);

            return (new ParserFactory)->create(ParserFactory::PREFER_PHP7, $lexer, []);
        });

        $container->register('reflector', function (Container $container) {
            // HACK: for testing purposes ...
            if ($source = $container->getParameter('source')) {
                $locator = new StringSourceLocator(Source::fromString($source));
            } else {
                $locator = new ComposerSourceLocator($classLoader);
            }

            $classLoader = $container->get('composer.class_loader');

            $sourceContextFactory = new SourceContextFactory($container->get('php_parser'));

            return new Reflector($locator, $sourceContextFactory);
        });

        $container->register('util.class', function () {
            return new ClassUtil();
        });
    }

    private function registerComposer(Container $container)
    {
        $container->register('composer.class_name_resolver', function (Container $container) {
            return new ClassNameResolver($container->get('composer.class_loader'));
        });

        $container->register('composer.class_loader', function (Container $container) {
            $bootstrap = $container->getParameter('autoload');

            if (!file_exists($bootstrap)) {
                throw new \InvalidArgumentException(sprintf(
                    'Could not locate bootstrap file "%s"', $bootstrap
                ));
            }

            $autoloader = require $bootstrap;

            if (!$autoloader instanceof ClassLoader) {
                throw new \RuntimeException('Autoloader is not an instance of ClassLoader');
            }

            return $autoloader;
        });
    }

    private function registerConsole(Container $container)
    {
        $container->register('application', function (Container $container) {
            $application = new Application(self::APP_NAME, self::APP_VERSION);
            $application->addCommands([
                $container->get('command.explain'),
                $container->get('command.complete'),
                $container->get('command.generate'),
            ]);

            return $application;
        });

        $container->register('command.generate', function (Container $container) {
            return new GenerateSnippetCommand($container->get('generator.snippet_creator'));
        });

        $container->register('command.complete', function (Container $container) {
            return new CompleteCommand($container->get('completer'));
        });

        $container->register('command.explain', function (Container $container) {
            return new ExplainCommand(
                $container->get('reflector'),
                $container->get('util.class')
            );
        });
    }

    private function registerGeneration(Container $container)
    {
        $container->register('generator.snippet_creator', function (Container $container) {
            return new SnippetCreator($container->get('generator.registry.snippet'));
        });

        $container->register('generator.registry.snippet', function (Container $container) {
            $registry = new SnippetGeneratorRegistry();
            $registry->register(
                'implement_missing_methods',
                $container->get('generator.snippet.implement_missing_methods')
            );
            $registry->register(
                'implement_missing_properties',
                $container->get('generator.snippet.implement_missing_properties')
            );
            $registry->register(
                'class',
                $container->get('generator.snippet.class')
            );

            return $registry;
        });

        $container->register('generator.snippet.implement_missing_methods', function(Container $container) {
            return new ImplementMissingMethodsGenerator(
                $container->get('reflector'),
                $container->get('util.class')
            );
        });

        $container->register('generator.snippet.implement_missing_properties', function(Container $container) {
            return new MissingPropertiesGenerator(
                $container->get('reflector'),
                $container->get('util.class')
            );
        });

        $container->register('generator.snippet.class', function(Container $container) {
            return new ClassGenerator(
                $container->get('composer.class_name_resolver')
            );
        });
    }
}
