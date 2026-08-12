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
        #[Autowire('%app.enabled_locales%')]
        private array $enabledLocales,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $locale = $event->getRequest()->getPreferredLanguage($this->enabledLocales);
        $this->translatableListener->setTranslationFallback(true);
        $this->translatableListener->setTranslatableLocale($locale);
    }
}
