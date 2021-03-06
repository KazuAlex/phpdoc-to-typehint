<?php

/*
 * This file is part of the PHPDoc to Type Hint package.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare (strict_types = 1);

namespace Dunglas\PhpDocToTypeHint;

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\Fqsen;
use phpDocumentor\Reflection\Php\Class_;
use phpDocumentor\Reflection\Php\File;
use phpDocumentor\Reflection\Php\Interface_;
use phpDocumentor\Reflection\Php\Project;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Null_;

/**
 * Parses DocBlocks and adds relative scalar type hints
 * to functions and methods.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class Converter
{
    const OBJECT_CLASS = 0;
    const OBJECT_INTERFACE = 1;
    const OBJECT_TRAIT = 2;
    const OBJECT_FUNCTION = 3;

    /**
     * @link http://php.net/manual/en/functions.arguments.php#functions.arguments.type-declaration
     */
    const TYPES = [
        'array',
        'bool',
        'callable',
        'float',
        'int',
        'self',
        'string',
    ];

    /**
     * Converts the given file.
     *
     * @param Project $project
     * @param File    $file
     * @param bool    $nullableTypes
     *
     * @return string
     */
    public function convert(Project $project, File $file, bool $nullableTypes): string
    {
        $tokens = token_get_all($file->getSource());

        $output = '';
        $outsideFunctionSignature = false;
        $insideFunctionSignature = false;
        $insideNamespace = false;
        $objectType = self::OBJECT_FUNCTION;
        $outputTypeHint = true;
        $outputDefaultValue = false;
        $outputReturn = true;
        $paramByReference = false;
        $namespace = null;
        $object = null;
        $function = null;
        $level = 0;

        foreach ($tokens as $token) {
            if (is_string($token)) {
                $outputToken = true;

                switch ($token) {
                    case '(':
                        if ($outsideFunctionSignature) {
                            $insideFunctionSignature = true;
                            $outsideFunctionSignature = false;
                            $outputTypeHint = true;
                        }
                        break;

                    case ':':
                        if (null !== $function) {
                            $outputReturn = false;
                        }
                    break;

                    case ')':
                        if ($insideFunctionSignature) {
                            if ($outputDefaultValue) {
                                $output .= ' = null';
                            }

                            $insideFunctionSignature = false;
                        }
                        break;

                    case ',':
                        if ($insideFunctionSignature && $outputDefaultValue) {
                            $output .= ' = null';
                        }
                        break;

                    case '&':
                        if ($insideFunctionSignature) {
                            $paramByReference = true;
                            $outputToken = false;
                        }
                        break;

                    case '=':
                        if ($insideFunctionSignature) {
                            $outputDefaultValue = false;
                        }
                        break;

                    case ';':
                    case '{':
                        if (null !== $function && $outputReturn) {
                            $return = $this->getReturn($project, $objectType, $namespace, $object, $function);

                            if ($return && $return[0] && (!$return[1] || $nullableTypes)) {
                                if ($endsInNewLine = $this->endsInNewLine($output)) {
                                    $output = substr($output, 0, -strlen($endsInNewLine));
                                }

                                $output .= sprintf(': %s%s', $return[1] ? '?' : '', $return[0]);

                                if ($endsInNewLine) {
                                    $output .= $endsInNewLine;
                                }
                            }

                            $function = null;
                            $outputReturn = false;
                        }

                        if ('{' === $token) {
                            ++$level;
                        }

                        if (';' === $token && $insideNamespace) {
                            $insideNamespace = false;
                        }
                    break;

                    case '}':
                        --$level;

                        if (0 === $level) {
                            $objectType = self::OBJECT_FUNCTION;
                        }
                        break;
                }

                if ($outputToken) {
                    $output .= $token;
                }

                continue;
            }

            list($id, $text) = $token;

            if ($paramByReference && $id !== T_VARIABLE) {
                // Not an actual argument passed by reference,
                // only an ampersand in a function signature
                $output .= '&';
                $paramByReference = false;
            }

            switch ($id) {
                case T_NAMESPACE:
                    $insideNamespace = true;
                    $namespace = '\\';
                    break;

                case T_STRING:
                    if ($outsideFunctionSignature) {
                        $function = $text;
                    }

                    if (null === $object && $objectType !== self::OBJECT_FUNCTION) {
                        $object = $text;
                    }
                    // No break

                case T_NS_SEPARATOR:
                case T_STRING:
                    if ($insideNamespace) {
                        $namespace .= $text;
                    }
                    // No break

                case T_STRING:
                case T_ARRAY:
                case T_CALLABLE:
                    if ($insideFunctionSignature) {
                        $outputTypeHint = false;
                    }
                    break;

                case T_CLASS:
                    $objectType = self::OBJECT_CLASS;
                    $object = null;
                    $level = 0;
                    break;

                case T_INTERFACE:
                    $objectType = self::OBJECT_INTERFACE;
                    $object = null;
                    $level = 0;
                    break;

                case T_TRAIT:
                    $objectType = self::OBJECT_TRAIT;
                    $object = null;
                    $level = 0;
                    break;

                case T_FUNCTION:
                    $outsideFunctionSignature = true;
                    $outputDefaultValue = false;
                    $outputReturn = true;
                    break;

                case T_VARIABLE:
                    if ($insideFunctionSignature) {
                        $outputDefaultValue = false;

                        if (null !== $function && $outputTypeHint) {
                            $parameter = $this->getParameter($project, $objectType, $namespace, $object, $function, $text);

                            if ($parameter) {
                                $outputDefaultValue = (bool) $parameter[1];
                                if ($outputDefaultValue && $nullableTypes) {
                                    $output .= '?';
                                    $outputDefaultValue = false;
                                }

                                $output .= $parameter[0].' ';
                            }

                            if ($paramByReference) {
                                $output .= '&';
                                $paramByReference = false;
                            }

                            $output .= $text;
                            $outputTypeHint = true;

                            continue 2;
                        }

                        if ($paramByReference) {
                            $output .= '&';
                            $paramByReference = false;
                        }

                        $outputTypeHint = true;
                    }

                    break;
            }

            $output .= $text;
        }

        return $output;
    }

    /**
     * Gets a DocBlock.
     *
     * @param Project     $project
     * @param int         $objectType
     * @param string|null $namespace
     * @param string|null $object
     * @param string      $function
     *
     * @return DocBlock|null
     */
    private function getDocBlock(Project $project, int $objectType, string $namespace = null, string $object = null, string $function)
    {
        switch ($objectType) {
            case self::OBJECT_FUNCTION:
                if (null === $namespace) {
                    $function = sprintf('\\%s()', $function);
                } else {
                    $function = sprintf('%s\\%s()', $namespace, $function);
                }

                foreach ($project->getFiles() as $file) {
                    return $this->getDocBlockForFunction($file->getFunctions(), $function);
                }

                return;

            case self::OBJECT_CLASS:
                $method = 'getClasses';
                break;

            case self::OBJECT_INTERFACE:
                $method = 'getInterfaces';
                break;

            case self::OBJECT_TRAIT:
                $method = 'getTraits';
                break;
        }

        $fqsen = $namespace.'\\'.$object;
        $fqfunction = sprintf('%s::%s()', $fqsen, $function);

        foreach ($project->getFiles() as $file) {
            foreach ($file->$method() as $obj) {
                if ($obj->getFqsen()->__toString() === $fqsen) {
                    $docBlock = $this->getDocBlockForFunction($obj->getMethods(), $fqfunction);

                    if (
                        self::OBJECT_TRAIT === $objectType ||
                        (null !== $docBlock && 0 !== strcasecmp('{@inheritdoc}', trim($docBlock->getSummary())))
                    ) {
                        return $docBlock;
                    }

                    if ($obj instanceof Class_) {
                        if ($docBlock = $this->getDocBlockForInterfaces($project, $obj->getInterfaces(), $function)) {
                            return $docBlock;
                        }

                        $parentFqsen = $obj->getParent();
                        if (null === $parentFqsen || !$parent = $this->getObject($project, $parentFqsen, self::OBJECT_CLASS)) {
                            return;
                        }

                        return $this->getDocBlock($project, self::OBJECT_CLASS, $this->getNamespace($parentFqsen), $parent->getName(), $function);
                    }

                    if ($obj instanceof Interface_ && $docBlock = $this->getDocBlockForInterfaces($project, $obj->getParents(), $function)) {
                        return $docBlock;
                    }
                }
            }
        }
    }

    /**
     * Extracts a namespace from a FQSEN.
     *
     * @param Fqsen $fqsen
     *
     * @return string
     */
    private function getNamespace(Fqsen $fqsen): string
    {
        $value = $fqsen->__toString();

        return substr($value, 0, strrpos($value, '\\'));
    }

    /**
     * Finds a Class_ or an Interface_ instance using its FQSEN.
     *
     * @param Project $project
     * @param Fqsen   $fqsen
     * @param int     $objectType
     *
     * @return Class_|Interface_|null
     */
    private function getObject(Project $project, Fqsen $fqsen, int $objectType)
    {
        $method = self::OBJECT_CLASS === $objectType ? 'getClasses' : 'getInterfaces';

        foreach ($project->getFiles() as $file) {
            foreach ($file->$method() as $object) {
                if ($object->getFqsen()->__toString() === $fqsen->__toString()) {
                    return $object;
                }
            }
        }
    }

    /**
     * Gets a DocBlock from an array of interfaces FQSEN instances.
     *
     * @param Project $project
     * @param Fqsen[] $fqsens
     * @param string  $function
     *
     * @return DocBlock|null
     */
    private function getDocBlockForInterfaces(Project $project, array $fqsens, string $function)
    {
        foreach ($fqsens as $fqsen) {
            $object = $this->getObject($project, $fqsen, self::OBJECT_INTERFACE);

            if (!$object) {
                continue;
            }

            $docBlock = $this->getDocBlock($project, self::OBJECT_INTERFACE, $this->getNamespace($fqsen), $object->getName(), $function);

            if ($docBlock) {
                return $docBlock;
            }
        }
    }

    /**
     * Gets the DocBlock of a function.
     *
     * @param array  $functions
     * @param string $function
     *
     * @return DocBlock|null
     */
    private function getDocBlockForFunction(array $functions, string $function)
    {
        foreach ($functions as $reflectionFunctionName => $reflectionFunction) {
            if ($function !== $reflectionFunctionName) {
                continue;
            }

            return $reflectionFunction->getDocblock();
        }
    }

    /**
     * Gets the type and nullability of the parameter of a function.
     *
     * @param Project     $project
     * @param int         $objectType
     * @param string|null $namespace
     * @param string|null $object
     * @param string      $function
     * @param string      $parameter
     *
     * @return array
     */
    private function getParameter(Project $project, int $objectType, string $namespace = null, string $object = null, string $function, string $parameter): array
    {
        $docBlock = $this->getDocBlock($project, $objectType, $namespace, $object, $function);

        if ($docBlock) {
            foreach ($docBlock->getTagsByName('param') as $tag) {
                if ($parameter !== sprintf('$%s', $tag->getVariableName())) {
                    continue;
                }

                return $this->getType($tag);
            }
        }

        return [];
    }

    /**
     * Gets the return type of a function.
     *
     * @param Project     $project
     * @param int         $objectType
     * @param string|null $namespace
     * @param string|null $object
     * @param string      $function
     *
     * @return array
     */
    private function getReturn(Project $project, int $objectType, string $namespace = null, string $object = null, string $function): array
    {
        $docBlock = $this->getDocBlock($project, $objectType, $namespace, $object, $function);

        if ($docBlock) {
            $tags = $docBlock->getTagsByName('return');
            if (1 !== count($tags)) {
                return [];
            }

            return $this->getType($tags[0]);
        }

        return [];
    }

    /**
     * Gets the parameter type and tries to find best-matching PHP type
     *
     * Commonly used type aliases are normalized and a whitelist for
     * all-lowercase types is applied.
     *
     * @param Tag $tag
     *
     * @return array
     */
    private function getType(Tag $tag): array
    {
        $type = $this->getTypeFromTag($tag);

        if (!$type) {
            return $type;
        }

        $typeDesc = $type[0];

        if ($typeDesc === strtolower($typeDesc)) {
            $typeDesc = $this->normalizeType($typeDesc);
            // match all-lowercase types against known types
            if (!in_array($typeDesc, static::TYPES)) {
                return [];
            }
        }

        return $type;
    }

    /**
     * Gets the type of the parameter or an empty array if it is not defined.
     *
     * @param Tag $tag
     *
     * @return array
     */
    private function getTypeFromTag(Tag $tag): array
    {
        $type = $tag->getType();

        if (null === $type) {
            // No type specified
            return [];
        }

        if ($type instanceof Compound) {
            if ($type->has(2)) {
                // Several types, cannot guess
                return [];
            }

            $type0 = $type->get(0);
            $type1 = $type->get(1);

            if ($type0 instanceof Null_ && $type1 instanceof Null_) {
                // No type hint
                return [];
            }

            if (!$type0 instanceof Null_ && $type1 instanceof Null_) {
                if ($type0 instanceof Array_) {
                    return ['array', true];
                }
                return [$type0->__toString(), true];
            }

            if (!$type1 instanceof Null_ && $type0 instanceof Null_) {
                if ($type1 instanceof Array_) {
                    return ['array', true];
                }
                return [$type1->__toString(), true];
            }

            // Mixed types, cannot guess
            return [];
        }

        if ($type instanceof Array_) {
            // May contain more specific type declarations, but we only
            // convert to pure arrays
            return ['array', false];
        }

        return [$type->__toString(), false];
    }

    /**
     * Determines if the string ends in a new line and returns it.
     *
     * Will also include additional space/tab character if present, i.e. the
     * indentation into the next line.
     *
     * Returns an empty string if no match was found.
     *
     * @param string $output
     * @return string
     */
    private function endsInNewLine(string $output): string
    {
        $result = preg_match('/((?:\r\n|\r|\n)[ \t]*)$/', $output, $matches);

        if (0 === $result) {
            return '';
        }

        return $matches[1];
    }

     /**
     * Normalizes the type.
     *
     * @link https://github.com/symfony/symfony/blob/d2d8d17a8068d76f42c42c7791f45ca68f4f98a4/src/Symfony/Component/PropertyInfo/Extractor/PhpDocExtractor.php#L317-L346
     * @license https://github.com/symfony/symfony/blob/d2d8d17a8068d76f42c42c7791f45ca68f4f98a4/src/Symfony/Component/PropertyInfo/LICENSE
     *
     * @param string $docType
     *
     * @return string
     */
    private function normalizeType($docType)
    {
        switch ($docType) {
            case 'integer':
                return 'int';

            case 'boolean':
                return 'bool';

            // real is not part of the PHPDoc standard, so we ignore it
            case 'double':
                return 'float';

            case 'callback':
                return 'callable';

            default:
                return $docType;
        }
    }
}
