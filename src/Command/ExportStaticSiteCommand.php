<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PartIconResolver;
use App\Service\PrimeSetSnapshotStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Twig\Environment;

#[AsCommand(name: 'app:export-static-site', description: 'Renders the current snapshot as a static GitHub Pages site.')]
final class ExportStaticSiteCommand extends Command
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PrimeSetSnapshotStore $snapshotStore,
        private readonly PartIconResolver $partIconResolver,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Directory where the static site is written.', 'dist')
            ->addOption('asset-base', null, InputOption::VALUE_REQUIRED, 'Prefix for generated asset URLs.', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputDir = $this->resolveOutputDir((string) $input->getOption('output-dir'));
        $assetBase = (string) $input->getOption('asset-base');

        $snapshot = $this->snapshotStore->loadSnapshot();
        if ($snapshot === null) {
            $io->error('No snapshot found. Run app:warm-prime-set-cache before exporting the static site.');

            return Command::FAILURE;
        }

        $sets = $this->resolvePartIcons($snapshot['sets'] ?? []);
        $html = $this->twig->render('home/index.html.twig', [
            'generatedAt' => isset($snapshot['generatedAt']) ? new \DateTimeImmutable((string) $snapshot['generatedAt']) : null,
            'sets' => $sets,
            'stats' => $snapshot['stats'] ?? ['setCount' => 0, 'cheaperAsSet' => 0, 'cheaperAsParts' => 0],
            'refreshing' => false,
            'refreshState' => null,
            'refreshStatusData' => null,
            'hasSnapshot' => true,
            'staticMode' => true,
            'assetBase' => $assetBase,
        ]);

        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            $io->error(sprintf('Could not create output directory "%s".', $outputDir));

            return Command::FAILURE;
        }

        file_put_contents($outputDir.'/index.html', $html);
        $io->success(sprintf('Static site exported to "%s".', $outputDir));

        return Command::SUCCESS;
    }

    /**
     * @param mixed $sets
     * @return list<array<string, mixed>>
     */
    private function resolvePartIcons(mixed $sets): array
    {
        if (!is_array($sets)) {
            return [];
        }

        foreach ($sets as &$set) {
            if (!is_array($set)) {
                continue;
            }

            $category = (string) ($set['category'] ?? 'other');
            $parts = $set['parts'] ?? [];
            if (!is_array($parts)) {
                continue;
            }

            foreach ($parts as &$part) {
                if (!is_array($part)) {
                    continue;
                }

                $part['imageUrl'] = $this->partIconResolver->resolve(
                    $category,
                    (string) ($part['slug'] ?? ''),
                    isset($part['imageUrl']) && is_string($part['imageUrl']) ? $part['imageUrl'] : null,
                );
            }

            unset($part);
            $set['parts'] = $parts;
        }

        unset($set);

        return array_values(array_filter($sets, static fn (mixed $set): bool => is_array($set)));
    }

    private function resolveOutputDir(string $outputDir): string
    {
        if (str_starts_with($outputDir, '/')) {
            return rtrim($outputDir, '/');
        }

        return $this->projectDir.'/'.trim($outputDir, '/');
    }
}
