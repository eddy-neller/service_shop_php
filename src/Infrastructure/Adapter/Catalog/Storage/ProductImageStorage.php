<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Catalog\Storage;

use App\Application\Catalog\Port\ProductImageStorageInterface;
use App\Application\Shared\Port\FileInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Depose les images de produit sur le disque local.
 *
 * Le nom est aleatoire et l'extension est derivee du MIME controle par le validateur :
 * aucun nom fourni par le client n'atteint le systeme de fichiers.
 */
final readonly class ProductImageStorage implements ProductImageStorageInterface
{
    /**
     * `Filesystem::copy()` reporte les droits de la source sur la cible
     * (`fileperms($origin) & 0777 & ~umask()`). La source étant le fichier temporaire
     * d'upload PHP — créé en 0600 — l'avatar héritait de droits que le worker nginx,
     * autre utilisateur dans un autre conteneur, ne peut pas lire : 403 sur l'image.
     * On fixe donc les droits explicitement, sans dépendre du tmp ni de l'umask de php-fpm.
     */
    private const int FILE_MODE = 0644;

    private const string FILE_NAME_PATTERN = '/^[a-z0-9]{32}(?:\.(?:jpg|jpeg|png|webp))?$/D';

    public function __construct(
        private ParameterBagInterface $parameterBag,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {
    }

    public function store(FileInterface $file): string
    {
        $fileName = $this->generateFileName($file);

        try {
            $this->filesystem->mkdir($this->uploadDirectory(), 0755);
            $this->filesystem->copy($file->getPathname(), $this->pathFor($fileName), true);
            $this->filesystem->chmod($this->pathFor($fileName), self::FILE_MODE);
        } catch (IOExceptionInterface $exception) {
            throw new RuntimeException('Unable to store product image.', previous: $exception);
        }

        return $fileName;
    }

    public function remove(string $fileName): void
    {
        if (1 !== preg_match(self::FILE_NAME_PATTERN, $fileName)) {
            $this->logger->warning('Unable to delete product image with an invalid file name.', [
                'image' => $fileName,
            ]);

            return;
        }

        try {
            $this->filesystem->remove($this->pathFor($fileName));
        } catch (IOExceptionInterface $exception) {
            $this->logger->warning('Unable to delete product image file.', [
                'image' => $fileName,
                'exception' => $exception,
            ]);
        }
    }

    private function generateFileName(FileInterface $file): string
    {
        $extension = match ($file->getMimeType()) {
            'image/jpeg', 'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException(sprintf('Unsupported product image MIME type "%s".', $file->getMimeType())),
        };

        return bin2hex(random_bytes(16)) . '.' . $extension;
    }

    private function pathFor(string $fileName): string
    {
        if (1 !== preg_match(self::FILE_NAME_PATTERN, $fileName)) {
            throw new RuntimeException('Invalid product image file name.');
        }

        return rtrim($this->uploadDirectory(), '/') . '/' . $fileName;
    }

    private function uploadDirectory(): string
    {
        return (string) $this->parameterBag->get('app.product_image.upload_directory');
    }
}
