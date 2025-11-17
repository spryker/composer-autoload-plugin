<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Input\InputInterface;

class AutoloadPlugin implements PluginInterface, EventSubscriberInterface
{
    private const CALLBACK_PRIORITY = 50100;

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
     * @return bool
     */
    protected function getOption(IOInterface $io, string $optionName): bool
    {
        $ioReflection = new \ReflectionClass($io);

        $inputReflection = $ioReflection->getProperty('input');
        $inputReflection->setAccessible(true);

        /** @var InputInterface $input */
        $input = $inputReflection->getValue($io);

        if (!$input->hasOption($optionName)) {
            return false;
        }

        return $input->getOption($optionName);
    }

    protected function addSplitNamespaces(): void
    {
        $package  = $this->composer->getPackage();
        $namespacesToSplit = $package->getExtra()['splitting']['namespaces'] ?? ['Spryker\\'];

        $autoload = $package->getAutoload();
        $psr4     = $autoload['psr-4'] ?? [];
        $root     = getcwd();

        foreach ($namespacesToSplit as $namespace) {
            if (!isset($psr4[$namespace])) {
                continue;
            }

            $unprocessedFolders = [];
            foreach ($psr4[$namespace] as $folder) {
                $folderProcessed = false;
                $dirs = glob($folder . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR, GLOB_ONLYDIR) ?: [];
                foreach ($dirs as $dir) {
                    $pathParts = explode(DIRECTORY_SEPARATOR, trim($dir, DIRECTORY_SEPARATOR));
                    $module = array_pop($pathParts);
                    $layer = array_pop($pathParts);
                    if (in_array($layer, ['Shared', 'Service', 'Client', 'Yves', 'Glue', 'Zed']) === false) {
                        continue;
                    }
                    $psr4[$namespace . $layer . '\\' . $module . '\\'] = [$dir];
                    $folderProcessed = true;
                }
                if (!$folderProcessed) {
                    $unprocessedFolders[] = $folder;
                }
            }
            unset($psr4[$namespace]);
            if (count($unprocessedFolders) > 1) {
                $psr4[$namespace] = $unprocessedFolders;
            }
        }

        $autoload['psr-4'] = $psr4;
        $package->setAutoload($autoload);
    }
}
