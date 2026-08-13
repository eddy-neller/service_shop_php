<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\EventListener;

use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST)]
final readonly class LocaleListener
{
    public function __construct(
        #[Autowire('@stof_doctrine_extensions.listener.translatable')]
        private TranslatableListener $translatableListener,
        /** @var list<string> */
        #[Autowire('%app.enabled_locales%')]
        private array $enabledLocales,
        #[Autowire('%app.default_locale%')]
        private string $defaultLocale,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->translatableListener->setTranslationFallback(true);
        $this->translatableListener->setTranslatableLocale($this->localeFor($event));
    }

    /**
     * Le repli n'est pas cosmetique. `kernel.enabled_locales` est vide dans ce service, donc
     * `getPreferredLanguage([])` rend `null` des qu'une requete arrive sans `Accept-Language` —
     * ce que fait tout appel `curl`, toute sonde de supervision et tout client HTTP par defaut.
     * Gedmo refuse alors la locale vide et repond 500, y compris sur `GET /health`, dont le
     * contrat est justement de repondre seul et sans condition.
     */
    private function localeFor(RequestEvent $event): string
    {
        $preferred = $event->getRequest()->getPreferredLanguage($this->enabledLocales);

        return null === $preferred || '' === $preferred ? $this->defaultLocale : $preferred;
    }
}
