<?php

/*
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace SchemaOrgModel;

use Psr\Log\LoggerInterface;
use Symfony\CS\Config\Config;
use Symfony\CS\Fixer;

/**
 * Entities generator.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class TypesGenerator
{
    /**
     * @type string
     * @see https://github.com/myclabs/php-enum Used enum implementation
     */
    const ENUM_USE = 'MyCLabs\Enum\Enum';
    /**
     * @type string
     * @see https://github.com/myclabs/php-enum Used enum implementation
     */
    const ENUM_EXTENDS = 'Enum';
    /**
     * @type string
     */
    const SCHEMA_ORG_NAMESPACE = 'http://schema.org/';
    /**
     * @type string
     */
    const SCHEMA_ORG_ENUMERATION = 'http://schema.org/Enumeration';
    /**
     * @type string
     */
    const SCHEMA_ORG_DOMAIN = 'schema:domainIncludes';
    /**
     * @type string
     */
    const SCHEMA_ORG_RANGE = 'schema:rangeIncludes';

    /**
     * @type \Twig_Environment
     */
    private $twig;
    /**
     * @type LoggerInterface
     */
    private $logger;
    /**
     * @type \EasyRdf_Graph[]
     */
    private $graphs;
    /**
     * @type CardinalitiesExtractor
     */
    private $cardinalitiesExtractor;
    /**
     * @type GoodRelationsBridge
     */
    private $goodRelationsBridge;
    /**
     * @type array
     */
    private $cardinalities;

    /**
     * @param \Twig_Environment      $twig
     * @param LoggerInterface        $logger
     * @param \EasyRdf_Graph[]       $graphs
     * @param CardinalitiesExtractor $cardinalitiesExtractor
     * @param GoodRelationsBridge    $goodRelationsBridge
     */
    public function __construct(
        \Twig_Environment $twig,
        LoggerInterface $logger,
        array $graphs,
        CardinalitiesExtractor $cardinalitiesExtractor,
        GoodRelationsBridge $goodRelationsBridge
    )
    {
        $this->twig = $twig;
        $this->logger = $logger;
        $this->graphs = $graphs;
        $this->cardinalitiesExtractor = $cardinalitiesExtractor;
        $this->goodRelationsBridge = $goodRelationsBridge;

        $this->cardinalities = $this->cardinalitiesExtractor->extract();
    }

    /**
     * Generates files.
     */
    public function generate($config)
    {
        $baseClass = [
            'constants' => [],
            'fields' => [],
            'uses' => [],
        ];

        $typesDefined = !empty($config['types']);
        $typesToGenerate = [];

        if (empty($config['types'])) {
            foreach ($this->graphs as $graph) {
                $typesToGenerate = $graph->allOfType('rdfs:Class');
            }
        } else {
            foreach ($config['types'] as $key => $value) {
                $resource = null;
                foreach ($this->graphs as $graph) {
                    $resources = $graph->resources();

                    if (isset($resources[self::SCHEMA_ORG_NAMESPACE.$key])) {
                        $resource = $graph->resource(self::SCHEMA_ORG_NAMESPACE . $key, 'rdfs:Class');
                        break;
                    }
                }

                if ($resource) {
                    $typesToGenerate[] = $resource;
                } else {
                    $this->logger->critical('Type "{key}" cannot be found.', ['key' => $key]);
                }
            }
        }

        $classes = [];
        $propertiesMap = $this->createPropertiesMap($typesToGenerate);

        foreach ($typesToGenerate as $type) {
            $typeDefined = !empty($config['types'][$type->localName()]['properties']);
            if ($typeDefined) {
                $typeConfig = $config['types'][$type->localName()];
            }
            $typeIsEnum = $this->isEnum($type);

            $class = $baseClass;

            $class['name'] = $type->localName();
            $class['label'] = $type->get('rdfs:comment')->getValue();
            $class['resource'] = $type;

            if ($typeIsEnum) {
                // Enum
                $class['namespace'] = $typeDefined && $typeConfig['namespace'] ? $typeConfig['namespace'] : $config['namespaces']['enum'];
                $class['parent'] = self::ENUM_EXTENDS;
                $class['uses'][] = self::ENUM_USE;

                // Constants
                foreach ($this->graphs as $graph) {
                    foreach ($graph->allOfType($type->getUri()) as $instance) {
                        $class['constants'][$instance->localName()] = [
                            'name' => strtoupper(substr(preg_replace('/([A-Z])/', '_$1', $instance->localName()), 1)),
                            'resource' => $instance,
                            'value' => $instance->getUri(),
                        ];
                    }
                }
            } else {
                // Entities
                $class['namespace'] = $typeDefined && $typeConfig['namespaces']['class'] ? $typeConfig['namespaces']['class'] : $config['namespaces']['entity'];

                if ($config['useRte']) {
                    $class['interfaceNamespace'] = $typeDefined && $typeConfig['namespaces']['interface'] ? $typeConfig['namespaces']['interface'] : $config['namespaces']['interface'];
                    $class['interfaceName'] = sprintf('%sInterface', $type->localName());
                }

                // Parent
                $class['parent'] = $typeDefined ? $typeConfig['parent'] : null;
                if (null === $class['parent']) {
                    $numberOfSupertypes = count($type->all('rdfs:subClassOf'));

                    if ($numberOfSupertypes > 1) {
                        $this->logger->error(sprintf('The type "%s" has several supertypes. Using the first one.', $type->localName()));
                    }

                    $class['parent'] = $numberOfSupertypes ? $type->all('rdfs:subClassOf')[0]->localName() : false;
                }

                if ($typesDefined && $class['parent'] && !isset($config['types'][$class['parent']])) {
                    $this->logger->error(sprintf('The type "%s" (parent of "%s") doesn\'t exist', $class['parent'], $type->localName()));
                }
            }

            // Fields
            foreach ($propertiesMap[$type->getUri()] as $property) {
                // Ignore properties not set if using a config file
                if ($typeDefined && !isset($typeConfig['properties'][$property->localName()])) {
                    continue;
                }

                if ($config['checkIsGoodRelations']) {
                    if (!$this->goodRelationsBridge->exist($property->localName())) {
                        $this->logger->warning(sprintf('The property "%s" (type "%s") is not part of GoodRelations.', $property->localName(), $type->localName()));
                    }
                }

                // Ignore or warn when properties are legacy
                if (preg_match('/legacy spelling/', $property->get('rdfs:comment'))) {
                    if (empty($typeConfig['properties'])) {
                        $this->logger->info(sprintf('The property "%s" (type "%s") is legacy. Ignoring.', $property->localName(), $type->localName()));

                        continue;
                    } else {
                        $this->logger->warning(sprintf('The property "%s" (type "%s") is legacy.', $property->localName(), $type->localName()));
                    }
                }

                $ranges = [];
                if (isset($typeConfig['properties'][$property->localName()]['range']) && $typeConfig['properties'][$property->localName()]['range']) {
                    $ranges[] = $typeConfig['properties'][$property->localName()]['range'];
                } else {
                    foreach ($property->all(self::SCHEMA_ORG_RANGE) as $range) {
                        if (!$typesDefined || $this->isDatatype($range->localName()) || !empty($config['types'][$range->localName()])) {
                            // Force enums to Text
                            $ranges[] = $this->isEnum($range) ? 'Text' : $range->localName();
                        }
                    }
                }

                $numberOfRanges = count($ranges);
                if ($numberOfRanges > 1) {
                    $this->logger->error(sprintf('The property "%s" (type "%s") has several types. Using the first one.', $property->localName(), $type->localName()));
                }

                $class['fields'][$property->localName()] = [
                    'name' => $property->localName(),
                    'resource' => $property,
                    'range' => $ranges[0],
                ];
            }

            $classes[$type->localName()] = $class;
        }

        $annotationGenerators = [];
        foreach ($config['annotationGenerators'] as $class) {
            $generator = new $class($this->logger, $this->graphs, $this->cardinalities, $config, $classes);

            $annotationGenerators[] = $generator;
        }

        $generatedFiles = [];
        foreach ($classes as $className => $class) {
            $class['uses'] = $this->generateClassUses($annotationGenerators, $classes, $className);
            $class['annotations'] = $this->generateClassAnnotations($annotationGenerators, $className);
            $class['interfaceAnnotations'] = $this->generateInterfaceAnnotations($annotationGenerators, $className);

            foreach ($class['constants'] as $constantName => $constant) {
                $class['constants'][$constantName]['annotations'] = $this->generateConstantAnnotations($annotationGenerators, $className, $constantName);

            }

            foreach ($class['fields'] as $fieldName => $field) {
                $typeHint = false;
                if (!$this->isDatatype($field['range'])) {
                    if (isset($classes[$field['range']]['interfaceName'])) {
                        $typeHint = $classes[$field['range']]['interfaceName'];
                    } else {
                        $typeHint = $classes[$field['range']]['name'];
                    }
                }

                $class['fields'][$fieldName]['annotations'] = $this->generateFieldAnnotations($annotationGenerators, $className, $fieldName);
                $class['fields'][$fieldName]['getterAnnotations'] = $this->generateGetterAnnotations($annotationGenerators, $className, $fieldName);
                $class['fields'][$fieldName]['setterAnnotations'] = $this->generateSetterAnnotations($annotationGenerators, $className, $fieldName);
                $class['fields'][$fieldName]['typeHint'] = $typeHint;
            }

            $classDir = $this->namespaceToDir($config, $class['namespace']);

            if (!file_exists($classDir)) {
                mkdir($classDir, 0777, true);
            }

            $path = sprintf('%s%s.php', $classDir, $className);
            $generatedFiles[] = $path;
            file_put_contents(
                $path,
                $this->twig->render('class.php.twig', [
                    'header' => $config['header'],
                    'fieldVisibility' => $config['fieldVisibility'],
                    'class' => $class,
                ])
            );

            if (isset($class['interfaceNamespace'])) {
                $interfaceDir = $this->namespaceToDir($config, $class['interfaceNamespace']);

                if (!file_exists($interfaceDir)) {
                    mkdir($interfaceDir, 0777, true);
                }

                $path = sprintf('%s%s.php', $interfaceDir, $class['interfaceName']);
                $generatedFiles[] = $path;
                file_put_contents(
                    $path,
                    $this->twig->render('interface.php.twig', [
                        'header' => $config['header'],
                        'class' => $class,
                    ])
                );
            }
        }

        $this->fixCs($generatedFiles);
    }

    /**
     * Tests if a type is an enum.
     *
     * @param  \EasyRdf_Resource $type
     * @return bool
     */
    private function isEnum(\EasyRdf_Resource $type)
    {
        $subClassOf = $type->get('rdfs:subClassOf');

        return $subClassOf && $subClassOf->getUri() === self::SCHEMA_ORG_ENUMERATION;
    }

    /**
     * Create a maps between class an properties.
     *
     * @param  array $types
     * @return array
     */
    private function createPropertiesMap(array $types)
    {
        $typesAsString = [];
        $map = [];
        foreach ($types as $type) {
            $typesAsString[] = $type->getUri();
            $map[$type->getUri()] = [];
        }

        foreach ($this->graphs as $graph) {
            foreach ($graph->allOfType('rdf:Property') as $property) {
                foreach ($property->all(self::SCHEMA_ORG_DOMAIN) as $domain) {
                    if (in_array($domain->getUri(), $typesAsString)) {
                        $map[$domain->getUri()][] = $property;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Is this type a datatype?
     *
     * @param  string $type
     * @return bool
     */
    private function isDatatype($type)
    {
        return in_array($type, ['Boolean', 'DataType', 'Date', 'DateTime', 'Float', 'Integer', 'Number', 'Text', 'Time', 'URL']);
    }

    /**
     * Generates field's annotations.
     *
     * @param  \SchemaOrgModel\AnnotationGenerator\AnnotationGeneratorInterface[] $annotationGenerators
     * @param  string                                                             $className
     * @param  string                                                             $fieldName
     * @return array
     */
    private function generateFieldAnnotations($annotationGenerators, $className, $fieldName)
    {
        $annotations = [];
        foreach ($annotationGenerators as $generator) {
            $annotations = array_merge($annotations, $generator->generateFieldAnnotations($className, $fieldName));
        }

        return $annotations;
    }

    /**
     * Generates constant's annotations.
     *
     * @param  \SchemaOrgModel\AnnotationGenerator\AnnotationGeneratorInterface[] $annotationGenerators
     * @param  string                                                             $className
     * @param  string                                                             $constantName
     * @return array
     */
    private function generateConstantAnnotations(array $annotationGenerators, $className, $constantName)
    {
        $annotations = [];
        foreach ($annotationGenerators as $generator) {
            $annotations = array_merge($annotations, $generator->generateConstantAnnotations($className, $constantName));
        }

        return $annotations;
    }

    /**
     * Generates class' annotations.
     *
     * @param  \SchemaOrgModel\AnnotationGenerator\AnnotationGeneratorInterface[] $annotationGenerators
     * @param  string                                                             $className
     * @return array
     */
    private function generateClassAnnotations(array $annotationGenerators, $className)
    {
        $annotations = [];
        foreach ($annotationGenerators as $generator) {
            $annotations = array_merge($annotations, $generator->generateClassAnnotations($className));
        }

        return $annotations;
    }

    /**
     * Generates interface's annotations.
     *
     * @param  \SchemaOrgModel\AnnotationGenerator\AnnotationGeneratorInterface[] $annotationGenerators
     * @param  string                                                             $className
     * @return array
     */
    private function generateInterfaceAnnotations(array $annotationGenerators, $className)
    {
        $annotations = [];
        foreach ($annotationGenerators as $generator) {
            $annotations = array_merge($annotations, $generator->generateInterfaceAnnotations($className));
        }

        return $annotations;
    }

    /**
     * Generates getter's annotations.
     *
     * @param  \SchemaOrgModel\AnnotationGenerator\AnnotationGeneratorInterface[] $annotationGenerators
     * @param  string                                                             $className
     * @param  string                                                             $fieldName
     * @return array
     */
    private function generateGetterAnnotations(array $annotationGenerators, $className, $fieldName)
    {
        $annotations = [];
        foreach ($annotationGenerators as $generator) {
            $annotations = array_merge($annotations, $generator->generateGetterAnnotations($className, $fieldName));
        }

        return $annotations;
    }

    /**
     * Generates getter's annotations.
     *
     * @param  \SchemaOrgModel\AnnotationGenerator\AnnotationGeneratorInterface[] $annotationGenerators
     * @param  string                                                             $className
     * @param  string                                                             $fieldName
     * @return array
     */
    private function generateSetterAnnotations(array $annotationGenerators, $className, $fieldName)
    {
        $annotations = [];
        foreach ($annotationGenerators as $generator) {
            $annotations = array_merge($annotations, $generator->generateSetterAnnotations($className, $fieldName));
        }

        return $annotations;
    }

    /**
     * Generates uses.
     *
     * @param  \SchemaOrgModel\AnnotationGenerator\AnnotationGeneratorInterface[] $annotationGenerators
     * @param  array                                                              $classes
     * @param  string                                                             $className
     * @return array
     */
    private function generateClassUses($annotationGenerators, $classes, $className)
    {
        $uses = [];

        if (
            isset($classes[$className]['interfaceNamespace'])
            && $classes[$className]['interfaceNamespace'] !== $classes[$className]['namespace']
        ) {
            $uses[] = sprintf(
                '%s\\%s',
                $classes[$className]['interfaceNamespace'],
                $classes[$className]['interfaceName']
            );
        }

        foreach ($classes[$className]['fields'] as $field) {
            if (isset($classes[$field['range']]['interfaceName'])) {
                $use = sprintf(
                    '%s\\%s',
                    $classes[$field['range']]['interfaceNamespace'],
                    $classes[$field['range']]['interfaceName']
                );

                if (!in_array($use, $uses)) {
                    $uses[] = $use;
                }
            }
        }

        foreach ($annotationGenerators as $generator) {
            $uses = array_merge($uses, $generator->generateUses($className));
        }

        // Order uses alphabetically
        sort($uses);

        return $uses;
    }

    /**
     * Converts a namespace to a directory path according to PSR-4.
     *
     * @param  array  $config
     * @param  string $namespace
     * @return string
     */
    private function namespaceToDir($config, $namespace)
    {
        return sprintf('%s/%s/', $config['output'], strtr($namespace, '\\', '/'));
    }

    /**
     * Uses PHP CS Fixer to make generated files following PSR and Symfony Coding Standards.
     *
     * @param array $files
     */
    private function fixCs(array $files)
    {
        $fixer = new Fixer();
        $fixer->registerBuiltInConfigs();
        $fixer->registerBuiltInFixers();

        $config = new Config();
        $config->fixers($fixer->getFixers());

        $finder = [];
        foreach ($files as $file) {
            $finder[] = new \SplFileInfo($file);
        }

        $config->finder(new \ArrayIterator($finder));

        $fixer->fix($config);
    }
}
