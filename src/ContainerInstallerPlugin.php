<?php
namespace Mouf\ContainerInstaller;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Util\Filesystem;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Plugin\PluginInterface;

/**
 * RootContainer Installer for Composer.
 * (based on RobLoach's code for ComponentInstaller)
 */
class ContainerInstallerPlugin implements PluginInterface
{

	public function activate(Composer $composer, IOInterface $io)
	{
		$rootPackage =$composer->getPackage();
        if (isset($rootPackage)) {
            // Ensure we get the root package rather than its alias.
            while ($rootPackage instanceof AliasPackage) {
                $rootPackage = $rootPackage->getAliasOf();
            }

            // Make sure the root package can override the available scripts.
            if (method_exists($rootPackage, 'setScripts')) {
                $scripts = $rootPackage->getScripts();
                // Act on the "post-autoload-dump" command so that we can act on all
                // the installed packages.
                $scripts['post-autoload-dump']['rootcontainer-installer'] = 'Mouf\\ContainerInstaller\\ContainerInstallerPlugin::postAutoloadDump';
                $rootPackage->setScripts($scripts);
            }
        }
	}
	
    /**
     * Script callback; Acted on after the autoloader is dumped.
     */
    public static function postAutoloadDump(Event $event)
    {
        // Retrieve basic information about the environment and present a
        // message to the user.
        $composer = $event->getComposer();
        $io = $event->getIO();
        $io->write('<info>Compiling containers list</info>');

        $packages = self::getPackagesList($composer);
        
        $factoryList = array();
        
        foreach ($packages as $package) {
        	if (isset($package['extra']['container-interop']['container-factory'])) {
        		$factories = $package['extra']['container-interop']['container-factory'];

        		// Allowed values for container-factory can be one of:
        		// String: the code of the factory
        		// Array of strings: an array of factory code
        		// Factory descriptor: like { "name"=>"", "description"=>"toto", "factory"=>"code" }
        		// Array of factory descriptor: like [{ "name"=>"", "description"=>"toto", "factory"=>"code" }]
        		if (!is_array($factories) || self::isAssoc($factories)) {
        			$factories = array($factories);
        		}
                foreach ($factories as $key => $factory) {
                    if (!is_array($factory)) {
                    	if (isset($package['name'])) {
                    		$packageName = $package['name'];
                    	} else {
                    		$packageName = 'root';
                    	}
                        if (count($factories) == 0) {
                            $containerName = "Container for package ".$packageName;
                        } else {
                            $containerName = "Container number $key for package ".$packageName;
                        }
                        $factory = [
                            "name"=>$packageName.'_'.$key,
                            "description"=>$containerName,
                            "factory"=>$factory
                        ];
                        $factories[$key] = $factory;
                    }
                }
        		$factoryList = array_merge($factoryList, $factories);
        	}
        }
        
        // Now, we should merge this with the existing containers.php if it exists.
        if (file_exists("containers.php")) {
        	$existingFactoryList = require 'containers.php';
        } else {
        	$existingFactoryList = [];
        }

        if ($factoryList) {
        	// TODO: security checks
		// See if we can use Symfony's FileSystem.
	        $fp = fopen("containers.php", "w");
	        fwrite($fp, "<?php\nreturn [\n");
	        foreach ($factoryList as $factory) {
	        	// Let's see if the factory exists in the existing list:
	        	foreach ($existingFactoryList as $item) {
	        		if ($factory['name'] == $item['name']) {
	        			$factory = array_merge($item, $factory);
	        			break;
	        		}
	        	}
	        	if (!isset($factory['enable'])) {
	        		$factory['enable'] = true;
	        	}
	        	
	        	fwrite($fp, "    [\n");
	        	foreach ($factory as $key => $value) {
	        		if ($key == 'factory') {
	        			fwrite($fp, "        '$key' => ".$value.",\n");
	        		} else {
	        			fwrite($fp, "        '$key' => ".var_export($value, true).",\n");
	        		}
	        	}
	        	fwrite($fp, "    ],\n");
	        }
	        fwrite($fp, "];\n");
        }
    }

    /**
     * Returns if an array is associative or not.
     *
     * @param array $arr
     * @return boolean
     */
    private static function isAssoc($arr)
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
    

    /**
     * Returns the list of packages that contain containers.
     * 
     * @param Composer $composer
     * @return PackageInterface[]
     */
    protected static function getPackagesList(Composer $composer)
    {
    	// Get the available packages.
    	$allPackages = array();
    	$locker = $composer->getLocker();
    	if (isset($locker)) {
    		$lockData = $locker->getLockData();
    		$allPackages = isset($lockData['packages']) ? $lockData['packages'] : array();
    
    		// Also merge in any of the development packages.
    		$dev = isset($lockData['packages-dev']) ? $lockData['packages-dev'] : array();
    		foreach ($dev as $package) {
    			$allPackages[] = $package;
    		}
    	}
    	
    	$packages = array();
    
    	// Only add those packages that we can reasonably
    	// assume are components into our packages list
    	foreach ($allPackages as $package) {
    		$extra = isset($package['extra']) ? $package['extra'] : array();
    		if (isset($extra['container-interop']) && is_array($extra['container-interop'])) {
    			$packages[] = $package;
    		}
    	}
    
    	// Add the root package to the packages list.
    	$root = $composer->getPackage();
    	if ($root) {
    		$dumper = new ArrayDumper();
    		$package = $dumper->dump($root);
    		$package['is-root'] = true;
    		$packages[] = $package;
    	}
    
    	$packages = PackagesOrderer::reorderPackages($packages);
    	
    	return $packages;
    }
}
