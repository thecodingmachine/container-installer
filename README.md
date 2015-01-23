About Container-Installer
=========================

This project is a test project developed as a proof of concept while working on the [ContainerInterop](https://github.com/container-interop/container-interop/) project.

The big picture
---------------

The ultimate goal is to allow the application developer to easily create a "root container", 
that can automatically detect and add containers contained in other packages into a global 
composite container that can be used by the application.

Compared to the classical way of thinking about a web application, this is a paradigm shift.

**In a "classical" application**, packages added to the application may add new instances to the main and only DI container.
This is what SF2 bundles, ZF2 modules or Mouf2 packages are doing.

**Using this approach**, each package provides its own DI container that contains instances. DI containers are added
to a global container that is queried.

About this package
------------------

The goal of this package is simply to provide an easy way for application developers to detect DI containers that might
be declared in Composer packages they use.

This project adds an additional step to Composer "install", just after the Composer dumps the autoloader.
The Container-Installer will scan all packages *composer.json* files and will look for a section like:

```json
{
	"extra": {
		"container-interop": {
			"container-factory": "My\\ContainerFactory::getContainer"
		}
	}
}
```

This section actually declares container factories that can be bundled in the package.

The "container-factory" parameter must point to a function or a static method that returns the container.

Here is a sample implementation:

```php
class ContainerFactory {
	private static $container;

	/**
	 * This method is returning a configured container
	 *
	 * @param ContainerInterface $rootContainer
	 * @return ContainerInterface
	 */
	public static function getContainer(ContainerInterface $rootContainer) {
		if (!$this->container) {
			// Delegate dependencies fetching to the root container.
			$this->container = new Picotainer([
				"hello" => function(ContainerInterface $container) {
					return array('hello' => $container->get('world'));
				}
			], $rootContainer);
		}
		return $this->container;
	}
}
```

A quick note about this code: we are providing a [Picotainer container](http://mouf-php.com/packages/mouf/picotainer/README.md).
Picotainer is a minimalistic container fully compativle with the [ContainerInterop](https://github.com/container-interop/container-interop/) project.

**Important**: the factory takes one compulsory parameter: the `$rootContainer`. If some entries in your container are containing
*external dependencies* (dependencies that are not part of the container), then your container needs to be able
to delegate dependencies fetching to the $rootContainer. For instance, `PimpleInterop` can delegate dependencies fetching if
you pass another container as the first argument of the constructor.

Note: your package does not have to require the `mouf/container-installer` package. This is sweet because if 
other container aggregators follow the same convention (referencing factory code in `composer.json` extra section),
there can easily be many different implementations of a root-container (maybe one per framework). 



How to use a root container in your project?
============================================

This package will simply create a `containers.php` file at the root of your project.
This `containers.php` file will contain a list of containers in this form:

```php
<?php
return [
    [
        'name' => '__root___0',
        'description' => 'Container number 0 for package __root__',
        'factory' => My\ContainerFactory::getContainer,
        'enable' => true
    ],
];
```

Please note that the developer can enable or disable packages manually, using the 'enable' attribute.

From there it is up to the application developer to use that file.

Using Acclimate's CompositeContainer, a usage might look like this:

```php
use Acclimate\Container\CompositeContainer;

// Let's create a composite container
$rootContainer = new CompositeContainer();

// Let's get the containers list
$container_descriptors = require 'containers.php';

// Let's add containers to the root container.
foreach ($container_descriptors as $descriptor) {
    if (descriptor['enable']) {
        $container = $descriptor['factory']($rootContainer);
        $rootContainer->addContainer($container);
    }
}

$myEntry = $rootContainer->get('myEntry');
```

Allowed syntax
--------------
Those syntaxes are all valid to declare container factories in **composer.json**:

Simply declaring a container-factory **via callback**:

```json
{
	"extra": {
		"container-interop": {
			"container-factory": "My\\ContainerFactory::getContainer"
		}
	}
}
```

Declaring an **array of container-factories via callback**:

```json
{
	"extra": {
		"container-interop": {
			"container-factory": [
				"My\\ContainerFactory1::getContainer",
				"My\\ContainerFactory2::getContainer",
				"My\\ContainerFactory3::getContainer"
			]
		}
	}
}
```

Declaring a container-factory **descriptor** (it contains additionnal data about the factory):

```json
{
	"extra": {
		"container-interop": {
			"container-factory": {
				"name": "a unique name for the factory",
				"description": "a description of what the factory does",
				"factory": "My\\ContainerFactory::getContainer"
			}
		}
	}
}
```

Note: all parameters of a descriptor are optionnal, except for the "factory" part.

Declaring an **array of container-factory descriptors**:

```json
{
	"extra": {
		"container-interop": {
			"container-factory": 
			[{
				"name": "a unique name for the factory",
				"description": "a description of what the factory does",
				"factory": "My\\ContainerFactory::getContainer"
			},
			{
				"name": "a unique name for another factory",
				"description": "a description of what the factory does",
				"factory": "My\\ContainerFactory2::getContainer"
			}]
		}
	}
}
```


Benefits
--------
Each package provides its container. The package is not dependent on the DI container used in the application.
This way, we can provide packages that are framework agnostic.

Downsides
---------
The classical implementation of the composite controller might imply a performance hit. We will need to think of a way to 
improve the performance of the composite container (maybe by doing entries maps, mapping entries to their associated container...) 

About other projects
--------------------
This is not the only project working on the "one container per package" paradigm. The [FrameworkInterop project](https://github.com/mnapoli/framework-interop)
by @mnapoli is also taking the same route (although its scope is larger).

About performance
=================

The current implementation of RootContainer is relying on the Acclimate's CompositeContainer. It is 
a proof-of-concept and no effort has been done performance-wise.
The more container you have in your application, the lower the performance should be (linearly).

It does not mean however that performance cannot be improved. There are many possible strategies to improve performance,
like building a map of all entries associated to their respective container. This is going further than
the current scope of this project.
