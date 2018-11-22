<?php

namespace CopyPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Symfony\Component\Finder\Iterator\RecursiveDirectoryIterator;
use Composer\Script\Event;
use Composer\Installer\PackageEvents;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    const VENDOR_DIR_KEY = 'vendor-dir';
    const STRATEGY_KEY = 'copy-mapping-strategy';
    const FORCE_STRATEGY = 'force';
    const SIMPLE_STRATEGY = 'simple';
    const ROOT_KEY = 'copy-mapping-root';
    const COPY_SOURCE_KEY = 'copy-mapping';
    const DEFAULT_ROOT = './';

    protected $composer;
    protected $filesystem;
    protected $packageName;
    protected $io;
    protected $vendorDir;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->filesystem = new Filesystem();
        $this->composer = $composer;
        $this->io = $io;
        $this->vendorDir = rtrim($this->composer->getConfig()->get(self::VENDOR_DIR_KEY), DIRECTORY_SEPARATOR);
    }

    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_UNINSTALL => [['delete', 0]],
            ScriptEvents::POST_INSTALL_CMD => [['copyAll', 0]],
            ScriptEvents::POST_UPDATE_CMD => [['copyAll', 0]],
        ];
    }

    public function copyAll(Event $event)
    {
        $allPackages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $copyMappingPackages = array_filter($allPackages, function($package) {
            $extra = $package->getExtra();
            return isset($extra[self::COPY_SOURCE_KEY]);
        });
        foreach($copyMappingPackages as $pkg) {
            $this->copyRecursive($pkg);
        }
    }

    public function delete(PackageEvent $event) {
        $package = $event->getOperation()->getPackage();
        $this->deleteRecursive($package);
    }

    protected function copyRecursive(Package $package)
    {
        $composerExtra = $this->composer->getPackage()->getExtra();
        $packageExtra = $package->getExtra();
        $extra = array_merge($composerExtra, $packageExtra);
        $forceCopy = isset($extra[self::STRATEGY_KEY]) ? $extra[self::STRATEGY_KEY] : self::SIMPLE_STRATEGY;
        $extra[self::ROOT_KEY] = isset($extra[self::ROOT_KEY]) ? $extra[self::ROOT_KEY] : self::DEFAULT_ROOT;

        if(!$this->isNeedCopy($package, $extra)) {
            return null;
        }

        foreach($extra[self::COPY_SOURCE_KEY] as $from => $to) {
            $target = $extra[self::ROOT_KEY] . $to;
            $source = $this->vendorDir . DIRECTORY_SEPARATOR . $package->getPrettyName() . DIRECTORY_SEPARATOR . $from;
            if(is_file($source) && file_exists($target) && $forceCopy !== self::FORCE_STRATEGY ) {
                $this->writeDebug("Copy plugin: $target exist and strategy is simple. Did not copied. ({$package->getPrettyName()})");
                continue;
            }
            if(is_file($source)) {
                $path = pathinfo($target);
                if (!file_exists($path['dirname'])) {
                    mkdir($path['dirname'], 0755, true);
                }
                copy($source, $target);
                $this->writeDebug("Copy plugin: $source copied to $target. ({$package->getPrettyName()})");
                continue;
            }
            $it = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);
            $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::SELF_FIRST);
            $this->filesystem->ensureDirectoryExists($target);
            foreach ($ri as $file) {
                $targetPath = $target . DIRECTORY_SEPARATOR . $ri->getSubPathName();
                if ($file->isDir()) {
                    $this->filesystem->ensureDirectoryExists($targetPath);
                    continue;
                }
                if(file_exists($targetPath) && $forceCopy !== self::FORCE_STRATEGY) {
                    $this->writeDebug("Copy plugin: $targetPath exist and strategy is simple. Did not copied. ({$package->getPrettyName()})");
                    continue;
                }
                copy($file->getPathname(), $targetPath);
            }
            $this->writeDebug("Copy plugin: $source copied to $target. ({$package->getPrettyName()}) ");
        }
    }

    protected function deleteRecursive(Package $package) {
        $composerExtra = $this->composer->getPackage()->getExtra();
        $packageExtra = $package->getExtra();
        $extra = array_merge($composerExtra, $packageExtra);
        $extra[self::COPY_SOURCE_KEY] = (isset($extra[self::COPY_SOURCE_KEY]) && is_array($extra[self::COPY_SOURCE_KEY])) ? $extra[self::COPY_SOURCE_KEY] : [];
        if(!$extra[self::COPY_SOURCE_KEY]) {
            $this->writeDebug("Copy plugin: Nothing to delete. ({$package->getPrettyName()})");
            return null;
        }
        $extra[self::ROOT_KEY] = (isset($extra[self::ROOT_KEY]) && is_string($extra[self::ROOT_KEY])) ? $extra[self::ROOT_KEY] : self::DEFAULT_ROOT;
        foreach($extra[self::COPY_SOURCE_KEY] as $from => $to) {
            $target = $extra[self::ROOT_KEY] . $to;
            if(is_file($target) && is_writable($target)) {
                unlink($target);
                $this->writeDebug("Copy plugin: $target was deleted. ({$package->getPrettyName()})");
                continue;
            }
            $this->filesystem->removeDirectoryPhp($target);
            $this->writeDebug("Copy plugin: $target was deleted. ({$package->getPrettyName()})");
        }
    }

    protected function isNeedCopy(Package $package, $extra)
    {
        if(empty($extra[self::ROOT_KEY]) || !is_string($extra[self::ROOT_KEY]) || !is_dir($extra[self::ROOT_KEY]) || !is_writable($extra[self::ROOT_KEY])) {
            $this->writeDebug("Copy plugin: Root directory you specified dont exist or not writable. Did not copied. ({$package->getPrettyName()})");
            return false;
        }

        if(empty($extra[self::COPY_SOURCE_KEY]) || !is_array($extra[self::COPY_SOURCE_KEY])) {
            $this->writeDebug("Copy plugin: You need to specify target and source folders. Did not copied. ({$package->getPrettyName()})");
            return false;
        }

        foreach($extra[self::COPY_SOURCE_KEY] as $from => $to) {
            $source_directory = $this->vendorDir . DIRECTORY_SEPARATOR . $package->getPrettyName() . DIRECTORY_SEPARATOR . $from;
            if(!is_readable($source_directory)) {
                $this->writeDebug("Copy plugin: Source directory $source_directory unreachable. Did not copied. ({$package->getPrettyName()})");
                return false;
            }
        }

        return true;
    }

    protected function writeDebug($message)
    {
        if($this->io->isDebug()) {
            $this->io->write($message);
        }
    }
}