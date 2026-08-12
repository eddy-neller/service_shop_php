<?php

declare(strict_types=1);

namespace App\Infrastructure\ApiPlatform\Serializer\ContextBuilder;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\State\SerializerContextBuilderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

#[AsDecorator(decorates: 'api_platform.serializer.context_builder')]
final readonly class AutoGroup implements SerializerContextBuilderInterface
{
    public function __construct(
        #[AutowireDecorated]
        private SerializerContextBuilderInterface $decorated,
    ) {
    }

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $context = $this->decorated->createFromRequest($request, $normalization, $extractedAttributes);
        $operation = $context['operation'] ?? null;

        if ($operation) {
            if (!empty($context['groups'])) {
                $context['groups'] = array_unique(array_merge($context['groups'], $this->getDefaultGroups($operation, $normalization)));
            } else {
                $context['groups'] = $this->getDefaultGroups($operation, $normalization);
            }
        }

        return $context;
    }

    /**
     * Creates the default groups for an API Platform operation.
     */
    private function getDefaultGroups(object $operation, bool $normalization): array
    {
        $resourceShortName = $operation->getShortName();

        if (null === $resourceShortName) {
            return [];
        }

        $shortName = new CamelCaseToSnakeCaseNameConverter()->normalize($resourceShortName);
        $operationName = $operation->getName();
        $readOrWrite = $normalization ? 'read' : 'write';
        $itemOrCol = $operation instanceof GetCollection ? 'col' : 'item';

        return [
            $readOrWrite,
            $shortName,
            sprintf('%s:%s', $shortName, $readOrWrite),
            sprintf('%s:%s:%s', $shortName, $itemOrCol, $readOrWrite),
            sprintf('%s:%s:%s', $shortName, $itemOrCol, $operationName),
            sprintf('%s:%s:%s:%s', $shortName, $itemOrCol, $operationName, $readOrWrite),
        ];
    }
}
