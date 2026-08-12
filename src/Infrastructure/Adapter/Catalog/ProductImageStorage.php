<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Catalog;

use App\Application\Shared\Port\FileInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Depose les images de produit sur le disque local.
 *
 * Remplace VichUploader, qui etait branche sur les evenements de cycle de vie de Doctrine
 * ORM. Ce service ne fait qu'une chose — deplacer un fichier et rendre son nom — ce qui
 * evite d'importer un bundle entier pour un `rename()`.
 *
 * Le nom est un hash sha256 tronque, comme le faisait `vich_uploader.namer_hash` : deux
 * envois du meme fichier ne creent pas deux copies, et aucun nom fourni par le client
 * n'atteint le systeme de fichiers.
 */
final readonly class ProductImageStorage
{
    private const int NAME_LENGTH = 32;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function store(FileInterface $file): string
    {
        $hash = hash_file('sha256', $file->getPathname());
        if (false === $hash) {
            throw new RuntimeException('Unable to hash the uploaded image.');
        }

        $extension = strtolower($file->getExtension());
        $name = substr($hash, 0, self::NAME_LENGTH) . ('' === $extension ? '' : '.' . $extension);

        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the image directory "%s".', $directory));
        }

        $destination = $directory . '/' . $name;

        // Meme contenu, meme nom : le fichier est deja la, le re-copier ne changerait rien.
        if (!is_file($destination) && !copy($file->getPathname(), $destination)) {
            throw new RuntimeException(sprintf('Unable to store the image at "%s".', $destination));
        }

        return $name;
    }

    public function remove(string $name): void
    {
        $path = $this->directory() . '/' . basename($name);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function directory(): string
    {
        // Doit rester aligne sur ProductImageUrlResolver::IMAGE_BASE_URL.
        return $this->projectDir . '/public/uploads/images/shop/product';
    }
}
