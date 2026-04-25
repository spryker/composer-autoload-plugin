<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\AliasPackage;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;

class AutoloadPlugin implements PluginInterface, EventSubscriberInterface
{
    private const CALLBACK_PRIORITY = -100;

    /**
     * @var \Composer\Composer
     */
    protected $composer;

    /**
     * @var \Composer\IO\IOInterface
     */
    protected $io;

    /**
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     *
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     *
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $io
     *
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @return array[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => ['preAutoloadDump', static::CALLBACK_PRIORITY],
        ];
    }

    /**
     * @param \Composer\Script\Event $event
     *
     * @return void
     */
    public function preAutoloadDump(ScriptEvent $event): void
    {
        $this->addSplitNamespaces();
    }

    /**
     * @param string $projectRoot
     *
     * @return array<string, mixed>
     */
    protected function readProjectConfig(string $projectRoot): array
    {
        $composerFile = $projectRoot . DIRECTORY_SEPARATOR . \Composer\Factory::getComposerFile();
        if (!file_exists($composerFile)) {
            return [];
        }

        $composerData = json_decode(file_get_contents($composerFile), true) ?? [];

        return $composerData['extra']['splitting'] ?? [];
    }

    protected function addSplitNamespaces(): void
    {
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $rootDir = dirname($vendorDir);
        $splittingConfig = $this->readProjectConfig($rootDir);

        if ($splittingConfig === []) {
            return;
        }

        $namespacesToSplit = $splittingConfig['namespaces'] ?? ['Spryker\\', 'SprykerShop\\'];
        $layers = $splittingConfig['layers'] ?? ['Shared', 'Service', 'Client', 'Yves', 'Glue', 'Zed'];

        $repository = $this->composer->getRepositoryManager()->getLocalRepository();
        $relativeVendorDir = substr($vendorDir, strlen($rootDir) + 1);

        foreach ($repository->getPackages() as $installedPackage) {
            if ($installedPackage instanceof CompleteAliasPackage) {
                $installedPackage = $installedPackage->getAliasOf();
            }

            if ($installedPackage instanceof AliasPackage) {
                continue;
            }

            $packageAutoload = $installedPackage->getAutoload();
            $psr4 = $packageAutoload['psr-4'] ?? [];
            if (!$psr4) {
                continue;
            }
            $namespaceProcessed = false;
            foreach ($psr4 as $namespace => $paths) {
                if (!in_array($namespace, $namespacesToSplit, true)) {
                    continue;
                }
                $unprocessedFolders = [];
                foreach (is_array($paths) ? $paths : [$paths] as $folder) {
                    $packagePathPrefix = $relativeVendorDir . DIRECTORY_SEPARATOR . $installedPackage->getName() . DIRECTORY_SEPARATOR . $folder;
                    $level1Dirs = glob($packagePathPrefix . '*' . DIRECTORY_SEPARATOR, GLOB_ONLYDIR) ?: [];
                    $splitLevel1Dirs = [];
                    $dirs = glob($packagePathPrefix . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR, GLOB_ONLYDIR) ?: [];
                    foreach ($dirs as $dir) {
                        $pathParts = explode(DIRECTORY_SEPARATOR, trim($dir, DIRECTORY_SEPARATOR));
                        $module = array_pop($pathParts);
                        $layer = array_pop($pathParts);
                        $splitLevel1Dirs[$packagePathPrefix . $layer . DIRECTORY_SEPARATOR] = true;
                        if (in_array($layer, $layers, true) === false) {
                            // Processes modules that does not follow Spryker module structure like src/SprykerShop/DateTimeConfiguratorPageExample/src/SprykerShop/Configurator/
                            $psr4[$namespace . $layer . '\\'] = $folder . $layer;

                            continue;
                        }
                        $psr4[$namespace . $layer . '\\' . $module . '\\'] = $folder . $layer . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR;
                    }

                    // Keep $folder as a fallback when at least one level-1 subdirectory was not split out
                    // (e.g. Spryker/Traits/ contains only PHP files and produced no depth-2 matches).
                    foreach ($level1Dirs as $level1Dir) {
                        if (!isset($splitLevel1Dirs[$level1Dir])) {
                            $unprocessedFolders[] = $folder;

                            break;
                        }
                    }
                }
                unset($psr4[$namespace]);
                if ($unprocessedFolders !== []) {
                    $psr4[$namespace] = $unprocessedFolders;
                }
                $namespaceProcessed = true;
            }
            if (!$namespaceProcessed) {
                continue;
            }
            $packageAutoload['psr-4'] = $psr4;
            $installedPackage->setAutoload($packageAutoload);
        }
    }
}
