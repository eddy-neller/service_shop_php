<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Unit\Symfony\Command\Catalog;

use App\Infrastructure\Persistence\Mongo\Catalog\CategoryDocument;
use App\Infrastructure\Persistence\Mongo\Catalog\ProductDocument;
use App\Infrastructure\Symfony\Command\Catalog\NbProductCommand;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Persistence\ObjectRepository;
use MongoDB\Collection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class NbProductCommandTest extends TestCase
{
    public function testNoModification(): void
    {
        $tester = $this->getCommandTesterForScenario(5, 5);
        $tester->execute([]);

        self::assertStringContainsString('Aucune modification effectuée.', $tester->getDisplay());
    }

    public function testModificationDetected(): void
    {
        $tester = $this->getCommandTesterForScenario(2, 5);
        $tester->execute([]);

        self::assertStringContainsString('modification(s) effectuée(s)', $tester->getDisplay());
    }

    public function testVerboseOutput(): void
    {
        $tester = $this->getCommandTesterForScenario(2, 6);
        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('DIFFERENCE DETECTED.', $output);
        self::assertStringContainsString('FROM "2" TO "6"', $output);
    }

    public function testDebugOutputDoesNotWrite(): void
    {
        $tester = $this->getCommandTesterForScenario(2, 6, debug: true);
        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_DEBUG]);

        self::assertStringContainsString('sans écriture (debug)', $tester->getDisplay());
    }

    private function getCommandTesterForScenario(
        int $currentCount,
        int $actualCount,
        bool $debug = false,
    ): CommandTester {
        $category = new CategoryDocument();
        $category->id = 'f9abcf1d-8ab4-4983-bd6d-8fc84cb6fb32';
        $category->title = 'TestCategory';
        $category->nbProduct = $currentCount;

        $categoryRepository = $this->createMock(ObjectRepository::class);
        $categoryRepository->expects(self::once())
            ->method('findAll')
            ->willReturn([$category]);

        $productCollection = $this->createMock(Collection::class);
        $productCollection->expects(self::once())
            ->method('countDocuments')
            ->with(['categoryId' => $category->id])
            ->willReturn($actualCount);

        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->expects(self::once())
            ->method('getRepository')
            ->with(CategoryDocument::class)
            ->willReturn($categoryRepository);
        $documentManager->expects(self::once())
            ->method('getDocumentCollection')
            ->with(ProductDocument::class)
            ->willReturn($productCollection);

        $shouldWrite = !$debug && $currentCount !== $actualCount;
        $documentManager->expects($shouldWrite ? self::once() : self::never())
            ->method('persist')
            ->with($category);
        $documentManager->expects($shouldWrite ? self::once() : self::never())
            ->method('flush')
            ->with(['withTransaction' => true]);

        return new CommandTester(new NbProductCommand($documentManager));
    }
}
