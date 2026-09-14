<?php

declare(strict_types=1);

namespace App\Infrastructure\ApiPlatform\Serializer\ContextBuilder;

use ApiPlatform\State\SerializerContextBuilderInterface;
use App\Infrastructure\Symfony\Security\RoleSet;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

/**
 * Adds the resource-specific admin group for authenticated administrators.
 */
#[AsDecorator(decorates: 'api_platform.serializer.context_builder')]
final readonly class AdminGroup implements SerializerContextBuilderInterface
{
    public function __construct(
        #[AutowireDecorated]
        private SerializerContextBuilderInterface $decorated,
        private Security $security,
    ) {
    }

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $context = $this->decorated->createFromRequest($request, $normalization, $extractedAttributes);

        if ($this->security->isGranted(RoleSet::ROLE_ADMIN)) {
            $operation = $context['operation'] ?? null;

            if ($operation) {
                $resourceShortName = $operation->getShortName();

                if (null === $resourceShortName) {
                    return $context;
                }

                $shortName = new CamelCaseToSnakeCaseNameConverter()->normalize($resourceShortName);
                $adminGroup = [sprintf('%s:admin', $shortName)];

                $context['groups'] = $context['groups'] ?? [];
                $context['groups'] = array_unique(array_merge($context['groups'], $adminGroup));
            }
        }

        return $context;
    }
}
