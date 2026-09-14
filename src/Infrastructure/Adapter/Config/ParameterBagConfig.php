<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Config;

use App\Application\Shared\Port\ConfigInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * @codeCoverageIgnore
 */
final readonly class ParameterBagConfig implements ConfigInterface
{
    public function __construct(
        private ParameterBagInterface $parameterBag,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->parameterBag->get($key);
    }

    public function getString(string $key, ?string $default = null): string
    {
        if (!$this->has($key)) {
            if (null === $default) {
                throw new RuntimeException(sprintf('Parameter "%s" not found and no default provided', $key));
            }

            return $default;
        }

        return (string) $this->parameterBag->get($key);
    }

    public function has(string $key): bool
    {
        return $this->parameterBag->has($key);
    }
}
