<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Videni\Bundle\RestBundle\Routing;

use ApiPlatform\Core\Api\IdentifiersExtractor;
use ApiPlatform\Core\Api\IdentifiersExtractorInterface;
use ApiPlatform\Core\Api\IriConverterInterface;
use ApiPlatform\Core\Api\OperationType;
use ApiPlatform\Core\Api\UrlGeneratorInterface;
use ApiPlatform\Core\DataProvider\ItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\OperationDataProviderTrait;
use ApiPlatform\Core\DataProvider\SubresourceDataProviderInterface;
use ApiPlatform\Core\Exception\InvalidArgumentException;
use ApiPlatform\Core\Exception\InvalidIdentifierException;
use ApiPlatform\Core\Exception\ItemNotFoundException;
use ApiPlatform\Core\Exception\RuntimeException;
use ApiPlatform\Core\Identifier\IdentifierConverterInterface;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Core\Util\AttributesExtractor;
use ApiPlatform\Core\Util\ClassInfoTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingExceptionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;

/**
 * {@inheritdoc}
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class IriConverter implements IriConverterInterface
{
    use ClassInfoTrait;
    use OperationDataProviderTrait;

    private $routeNameResolver;
    private $router;
    private $identifiersExtractor;
    private $propertyAccessor;

    public function __construct(PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory, PropertyMetadataFactoryInterface $propertyMetadataFactory, ItemDataProviderInterface $itemDataProvider, RouteNameResolverInterface $routeNameResolver, RouterInterface $router, PropertyAccessorInterface $propertyAccessor = null, IdentifiersExtractorInterface $identifiersExtractor = null, SubresourceDataProviderInterface $subresourceDataProvider = null, IdentifierConverterInterface $identifierConverter = null)
    {
        $this->itemDataProvider = $itemDataProvider;
        $this->routeNameResolver = $routeNameResolver;
        $this->router = $router;
        $this->identifiersExtractor = $identifiersExtractor;
        $this->subresourceDataProvider = $subresourceDataProvider;
        $this->identifierConverter = $identifierConverter;
        $this->propertyAccessor =  $propertyAccessor ?? PropertyAccess::createPropertyAccessor();

        if (null === $identifiersExtractor) {
            @trigger_error(sprintf('Not injecting "%s" is deprecated since API Platform 2.1 and will not be possible anymore in API Platform 3', IdentifiersExtractorInterface::class), E_USER_DEPRECATED);
            $this->identifiersExtractor = new IdentifiersExtractor($propertyNameCollectionFactory, $propertyMetadataFactory, $this->propertyAccessor);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getItemFromIri(string $iri, array $context = [])
    {
        try {
            $parameters = $this->router->match($iri);
        } catch (RoutingExceptionInterface $e) {
            throw new InvalidArgumentException(sprintf('No route matches "%s".', $iri), $e->getCode(), $e);
        }

        if (!isset($parameters['_api_resource_class'])) {
            throw new InvalidArgumentException(sprintf('No resource associated to "%s".', $iri));
        }

        $attributes = AttributesExtractor::extractAttributes($parameters);

        try {
            $identifiers = $this->extractIdentifiers($parameters, $attributes);
        } catch (InvalidIdentifierException $e) {
            throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
        }

        if ($this->identifierConverter) {
            $context[IdentifierConverterInterface::HAS_IDENTIFIER_CONVERTER] = true;
        }

        if (isset($attributes['subresource_operation_name'])) {
            if ($item = $this->getSubresourceData($identifiers, $attributes, $context)) {
                return $item;
            }

            throw new ItemNotFoundException(sprintf('Item not found for "%s".', $iri));
        }

        if ($item = $this->getItemData($identifiers, $attributes, $context)) {
            return $item;
        }

        throw new ItemNotFoundException(sprintf('Item not found for "%s".', $iri));
    }

    /**
     * {@inheritdoc}
     */
    public function getIriFromItem($item, int $referenceType = UrlGeneratorInterface::ABS_PATH, $context = []): string
    {
        $resourceClass = $this->getObjectClass($item);
        $routeName = $this->routeNameResolver->getRouteName($resourceClass, OperationType::ITEM);

        try {
            $identifiers = $this->generateIdentifiersUrl($this->identifiersExtractor->getIdentifiersFromItem($item), $resourceClass);

            return $this->router->generate($routeName, ['id' => implode(';', $identifiers)], $referenceType);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException(sprintf(
                'Unable to generate an IRI for the item of type "%s"',
                $resourceClass
            ), $e->getCode(), $e);
        } catch (RoutingExceptionInterface $e) {
            throw new InvalidArgumentException(sprintf(
                'Unable to generate an IRI for the item of type "%s"',
                $resourceClass
            ), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getIriFromResourceClass(string $resourceClass, int $referenceType = UrlGeneratorInterface::ABS_PATH): string
    {
        try {
            return $this->router->generate($this->routeNameResolver->getRouteName($resourceClass, OperationType::COLLECTION), [], $referenceType);
        } catch (RoutingExceptionInterface $e) {
            throw new InvalidArgumentException(sprintf('Unable to generate an IRI for "%s".', $resourceClass), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getItemIriFromResourceClass(string $resourceClass, array $identifiers, int $referenceType = UrlGeneratorInterface::ABS_PATH): string
    {
        try {
            return $this->router->generate($this->routeNameResolver->getRouteName($resourceClass, OperationType::ITEM), $identifiers, $referenceType);
        } catch (RoutingExceptionInterface $e) {
            throw new InvalidArgumentException(sprintf('Unable to generate an IRI for "%s".', $resourceClass), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSubresourceIriFromResourceClass(string $resourceClass, array $context, int $referenceType = UrlGeneratorInterface::ABS_PATH): string
    {
        try {
            return $this->router->generate($this->routeNameResolver->getRouteName($resourceClass, OperationType::SUBRESOURCE, $context), $context['subresource_identifiers'], $referenceType);
        } catch (RoutingExceptionInterface $e) {
            throw new InvalidArgumentException(sprintf('Unable to generate an IRI for "%s".', $resourceClass), $e->getCode(), $e);
        }
    }

    /**
     * Generate the identifier url.
     *
     * @throws InvalidArgumentException
     *
     * @return string[]
     */
    private function generateIdentifiersUrl(array $identifiers, string $resourceClass): array
    {
        if (0 === \count($identifiers)) {
            throw new InvalidArgumentException(sprintf(
                'No identifiers defined for resource of type "%s"',
                $resourceClass
            ));
        }

        if (1 === \count($identifiers)) {
            return [rawurlencode((string) array_values($identifiers)[0])];
        }

        foreach ($identifiers as $name => $value) {
            $identifiers[$name] = sprintf('%s=%s', $name, $value);
        }

        return $identifiers;
    }
}
