<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Command\Catalog;

use App\Infrastructure\Persistence\Mongo\Catalog\CategoryDocument;
use App\Infrastructure\Persistence\Mongo\Catalog\ProductDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repare le compteur denormalise `CategoryDocument::$nbProduct`.
 *
 * Les cas d'usage du catalogue le maintiennent deja dans la transaction qui ecrit le
 * produit. Cette commande n'est donc qu'un outil de reparation ponctuel. Elle pilote
 * directement sa transaction ODM, car elle n'est pas une orchestration applicative.
 */
#[AsCommand(
    name: 'app:shop:nb-product',
    description: 'Rebuild the product count denormalized on each category.',
    hidden: false,
)]
final class NbProductCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Category product count repair');

        $updates = 0;
        $debug = $output->isDebug();
        $productCollection = $this->documentManager->getDocumentCollection(ProductDocument::class);

        foreach ($this->documentManager->getRepository(CategoryDocument::class)->findAll() as $category) {
            if (!$category instanceof CategoryDocument) {
                continue;
            }

            if ($output->isVerbose()) {
                $io->section('[CATEGORY] : ' . $category->title);
            }

            $currentCount = $category->nbProduct;
            $actualCount = $productCollection->countDocuments(['categoryId' => $category->id]);

            if ($actualCount === $currentCount) {
                if ($debug) {
                    $io->text([
                        '',
                        'NB Items registered : ' . $currentCount,
                        'NB Items found : ' . $actualCount,
                    ]);
                }

                continue;
            }

            ++$updates;

            if ($output->isVerbose()) {
                $io->warning('DIFFERENCE DETECTED.');
                $io->info('FROM "' . $currentCount . '" TO "' . $actualCount . '".');
            }

            // Le mode debug conserve le comportement historique : il montre les
            // corrections, sans programmer d'ecriture ni ouvrir de transaction.
            if ($debug) {
                continue;
            }

            $category->nbProduct = $actualCount;
            $this->documentManager->persist($category);
        }

        if (!$debug && $updates > 0) {
            // `withTransaction` cree la session et encadre le flush ODM dans une
            // transaction MongoDB. Aucun repository n'est implique dans cette tache
            // technique autonome.
            $this->documentManager->flush(['withTransaction' => true]);
        }

        if (0 === $updates) {
            $io->success('Aucune modification effectuée.');
        } elseif ($debug) {
            $io->warning($updates . ' modification(s) détectée(s), sans écriture (debug).');
        } else {
            $io->warning($updates . ' modification(s) effectuée(s).');
        }

        return Command::SUCCESS;
    }
}
