<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Report;

use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Service as AssetService;
use Tsf\GatekeeperBundle\Service\ResultStore;

use function in_array;
use function sprintf;

/**
 * Writes the report into the asset tree: <folder>/<Class>.csv per class and <folder>/summary.md.
 * Files are overwritten in place, so asset versioning keeps the history. Works on every supported
 * Pimcore line, with or without the Custom Reports bundle.
 */
class AssetWriter
{
    /**
     * @param array{enabled: bool, folder: string, formats: string[], on_save: bool} $config
     */
    public function __construct(
        private readonly ResultStore $store,
        private readonly ReportBuilder $builder,
        private readonly array $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function getFolder(): string
    {
        return rtrim((string) ($this->config['folder'] ?? '/reports/completeness'), '/') ?: '/';
    }

    /**
     * @return string[] formats
     */
    public function getFormats(): array
    {
        return $this->config['formats'] ?? ['csv'];
    }

    /**
     * Writes every configured format for every class (or one class) and returns the asset paths
     *
     * @return string[]
     */
    public function writeAll(?string $className = null, bool $timestamp = false, ?\DateTimeImmutable $at = null): array
    {
        $at ??= new \DateTimeImmutable();
        $suffix = $timestamp ? '-' . $at->format('Ymd-Hi') : '';
        $written = [];

        if (in_array('csv', $this->getFormats(), true)) {
            $classes = $className !== null ? [$className] : $this->store->fetchClassNames();
            foreach ($classes as $class) {
                $rows = $this->store->fetchRows($class);
                $written[] = $this->write($class . $suffix . '.csv', $this->builder->csv($rows));
            }
        }

        if (in_array('md', $this->getFormats(), true)) {
            $written[] = $this->write(
                'summary' . $suffix . '.md',
                $this->builder->summaryMarkdown(
                    $this->store->fetchSummary($className),
                    $this->store->fetchMissingFieldFrequency($className),
                    $this->store->fetchRows($className, null, null, null, true),
                    $at
                )
            );
        }

        return $written;
    }

    /**
     * Creates or overwrites one text asset and returns its full path
     */
    public function write(string $filename, string $content): string
    {
        $folder = AssetService::createFolderByPath($this->getFolder());
        if (!$folder instanceof Asset\Folder) {
            throw new \RuntimeException(sprintf('Could not create the asset folder "%s".', $this->getFolder()));
        }

        $path = $folder->getRealFullPath() . '/' . $filename;
        $asset = Asset::getByPath($path);

        if ($asset instanceof Asset) {
            $asset->setData($content);
            $asset->save();
        } else {
            $asset = Asset::create($folder->getId(), [
                'filename' => $filename,
                'data' => $content,
            ]);
        }

        return $asset->getRealFullPath();
    }
}
