<?php

namespace Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Util\Filesystem;
use Symfony\Bundle\FrameworkBundle\Util\Mustache;

use Installer\Bundle\Bundle;
use Installer\Bundle\BundleCollection;
use Installer\Nspace\Nspace;
use Installer\Nspace\NspaceCollection;
use Installer\Prefix\Prefix;
use Installer\Prefix\PrefixCollection;
use Installer\Repository\Repository;
use Installer\Repository\RepositoryCollection;

/**
 * GenerateProjectCommand
 *
 * @author      Bertrand Zuchuat <bertrand.zuchuat@gmail.com>
 * @author      Clément Jobeili <clement.jobeili@gmail.com>
 */
class GenerateProjectCommand extends Command
{
    protected function configure()
    {
        $this
            ->setDefinition(array(
                new InputArgument('app', InputArgument::REQUIRED, 'Application name'),
                new InputArgument('vendor', InputArgument::REQUIRED, 'Vendor name'),
                new InputArgument('path', InputArgument::REQUIRED, 'Directory name (path)'),
            ))
            ->addOption('controller', null, InputOption::VALUE_REQUIRED, 'Your first controller name', 'Main')
            ->addOption('protocol', null, InputOption::VALUE_REQUIRED, 'git or http', 'git')
            ->addOption('session-start', null, InputOption::VALUE_NONE, 'To start session automatically')
            ->addOption('session-name', null, InputOption::VALUE_REQUIRED, 'Session name', 'symfony')
            ->addOption('orm', null, InputOption::VALUE_REQUIRED, 'doctrine or propel', null)
            ->addOption('odm', null, InputOption::VALUE_REQUIRED, 'mongodb', null)
            ->addOption('assetic', null, InputOption::VALUE_NONE, 'Enable assetic')
            ->addOption('swiftmailer', null, InputOption::VALUE_NONE, 'Enable swiftmailer')
            ->addOption('doctrine-migration', null, InputOption::VALUE_NONE, 'Enable doctrine migration')
            ->addOption('doctrine-fixtures', null, InputOption::VALUE_NONE, 'Enable doctrine fixtures')
            ->addOption('template-engine', null, InputOption::VALUE_REQUIRED, 'twig or php', 'twig')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Profile name', 'default')
            ->addOption('assets-symlink', null, InputOption::VALUE_NONE, 'Symlink for web assets')
            ->addOption('force-delete', null, InputOption::VALUE_NONE, 'Force re-generation of project')
            ->setName('generate:project')
            ->setDescription('Generate a Symfony2 project')
            ->setHelp(<<<EOT
The <info>generate:project</info> command generates a Symfony2 Project.
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->loadProfileFile($input->getOption('profile'));
        $this->checkOptionParameters($input);

        $filesystem = new Filesystem();

        $output->writeln('<info>Initializing Project</info>');
        $path = $this->getAbsolutePath($input->getArgument('path'));
        if($input->getOption('force-delete')) {
            if (is_dir($path)) {
                $output->writeln(sprintf('> Remove project on <comment>%s</comment>', $path));
                $this->removeProject($path, $filesystem);
            }
        }
        $this->checkPathAvailable($path, $filesystem);

        $output->writeln(sprintf('> Generate project on <comment>%s</comment>', $path));
        $this->generateProjectFolder($input, $filesystem);

        $bundlesCollection = $this->generateBundlesCollection($input, $config);
        $customConfig = $this->generateCustomConfigBundles($bundlesCollection);
        $bundles = $bundlesCollection->getFormatted(12);
        $namespacesCollection = $this->generateNamespacesCollection($input, $config);
        $namespaces = $namespacesCollection->getFormatted(4);
        $prefixCollection = $this->generatePrefixesCollection($input, $config);
        $prefixes = $prefixCollection->getFormatted(4);
        $this->findAndReplace($input, $bundles, $namespaces, $prefixes, $customConfig);
        $repositories = $this->getRepositoriesCollection($input, $config);
        $this->installRepositories($repositories, $input, $output);
        chdir($path);
        $output->writeln(' > <info>Generate bootstrap files</info>');
        exec('php bin/build_bootstrap.php');
        $output->writeln(' > <info>Assets install</info>');
        $option = ($input->getOption('assets-symlink')) ? ' --symlink' : '';
        exec(sprintf('php app/console assets:install%s web', $option));
        $output->writeln(sprintf(' > <info>Clear cache and log</info>'));
        $filesystem->remove('app/cache/dev');
        $filesystem->remove('app/logs/dev.log');

        if ($config->bundles->user || $config->namespaces->user ||
            $config->prefixes->user || $config->repositories->user) {
            $output->writeln(sprintf(' > <comment>Add your personalized config for this project</comment>'));
        }
    }

    /**
     * Check option parameters
     *
     * @param $input
     */
    private function checkOptionParameters($input)
    {
        if (!in_array($input->getOption('protocol'), array('git', 'http'))) {
            throw new \RuntimeException('Protocol error. Values accepted: git or http');
        }
        if (!in_array($input->getOption('template-engine'), array('twig', 'php'))) {
            throw new \RuntimeException('Template engine error. Values accepted: twig or php');
        }
    }

    /**
     * Remove project
     *
     * @param $path
     * @param $filesystem
     */
    private function removeProject($path, $filesystem)
    {
        /* Delete symlink */
        $bundle_path = $path.'/web/bundles';
        if (is_dir($bundle_path)) {
            foreach (scandir($bundle_path) as $file) {
                $file_path = $bundle_path.'/'.$file;
                if (('.' !== substr($file, 0, 1)) && (is_link($file_path))) {
                    unlink($file_path);
                }
            }
        }

        foreach (array('.git', 'app', 'src', 'vendor', 'web') as $file) {
            $file_path = sprintf('%s/%s', $path, $file);
            if (file_exists($file_path)) {
                $filesystem->remove($file_path);
            }
        }
    }
    
    /**
     * Generate Bundle Collection
     *
     * @param $input
     * @param $filesystem
     */
    private function checkPathAvailable($path, $filesystem)
    {
        if (!is_dir($path)) {
            $filesystem->mkdirs($path);
        }

        if (!is_writable($path)) {
            throw new \RuntimeException(sprintf("The path %s is not writable\n", $path));
        }

        $files = scandir($path);
        foreach (array('.git', 'app', 'src', 'vendor', 'web') as $file) {
            if (in_array($file, $files)) {
                throw new \RuntimeException(sprintf('The folder %s contain a symfony project. Use another destination', $path));
            }
        }
    }

    /**
     * Get Absolute Path
     *
     * @param $path
     */
    private function getAbsolutePath($path)
    {
        if ('./' === substr($path, 0, 2)) {
            $path = getcwd().'/'.substr($path, 2);
        } elseif ('/' !== substr($path, 0, 1)) {
            $path = getcwd().'/'.$path;
        }

        return $path;
    }

    /**
     * Generate Project Folder
     *
     * @param $input
     * @param $filesystem
     */
    private function generateProjectFolder($input, $filesystem)
    {
        $path = $input->getArgument('path');
        $app = $input->getArgument('app');
        $vendor = $input->getArgument('vendor');

        $filesystem->mirror(__DIR__.'/../Resources/skeleton/project', $path);
        $targetBundleDir = sprintf('%s/src/%s/%sBundle', $path, $vendor, $app);

        $filesystem->mkdirs($targetBundleDir);
        $filesystem->mirror(__DIR__.'/../Resources/skeleton/bundle', $targetBundleDir);

        $filesystem->rename($targetBundleDir.'/AppBundle.php', $targetBundleDir.'/'.$app.'Bundle.php');

        if ($controller = $input->getOption('controller')) {
            $filesystem->rename(
                $targetBundleDir.'/Controller/BundleController.php',
                $targetBundleDir.'/Controller/'.$controller.'Controller.php'
            );
            $filesystem->rename(
                $targetBundleDir.'/Resources/views/Bundle',
                $targetBundleDir.'/Resources/views/'.$controller
            );
        } else {
            $filesystem->remove($targetBundleDir.'/Controller/BundleController.php');
            $filesystem->remove($targetBundleDir.'/Resources/Views/Bundle');
            $filesystem->remove($targetBundleDir.'/Resources/config/routing.yml');
        }

        $extension = ('twig' === $input->getOption('template-engine')) ? 'php' : 'twig';
        $filesystem->remove($targetBundleDir.'/Resources/Views/layout.html.'.$extension);
        $filesystem->remove($targetBundleDir.'/Resources/Views/'.$controller.'/index.html.'.$extension);
        $filesystem->remove($targetBundleDir.'/Resources/Views/'.$controller.'/welcome.html.'.$extension);
        $filesystem->remove($path.'/app/Views/base.html.'.$extension);

        /* create empty folder */
        $filesystem->mkdirs($path.'/app/cache', 0777);
        $filesystem->mkdirs($path.'/app/logs', 0777);
        $filesystem->mkdirs($path.'/src', 0755);
        $filesystem->mkdirs($path.'/vendor', 0755);
        $filesystem->mkdirs($targetBundleDir.'/Resources/public');
        $filesystem->mkdirs($targetBundleDir.'/Tests');

        $filesystem->chmod($path.'/app/console', 0755);
    }

    /**
     * Generate Bundle Collection
     *
     * @param $input
     */
    private function generateBundlesCollection($input, $config)
    {
        $bundles = $config->bundles->installer;
        $bundlesCollection = new BundleCollection();
        $bundlesCollection->add(new Bundle(
                                            $bundles->framework->name,
                                            $bundles->framework->namespace
                                        ));
        $bundlesCollection->add(new Bundle(
                                            $bundles->monolog->name,
                                            $bundles->monolog->namespace
                                        ));
        if ('twig' === $input->getOption('template-engine')) {
            $bundlesCollection->add(new Bundle(
                                                $bundles->twig->name,
                                                $bundles->twig->namespace
                                            ));
        }
        if ($input->getOption('swiftmailer')) {
            $bundlesCollection->add(new Bundle(
                                                $bundles->swiftmailer->name,
                                                $bundles->swiftmailer->namespace
                                            ));
        }
        if ($input->getOption('assetic')) {
            $bundlesCollection->add(new Bundle(
                                                $bundles->assetic->name,
                                                $bundles->assetic->namespace
                                            ));
        }
        if ($input->getOption('orm')) {
            if ('doctrine' === $input->getOption('orm')) {
                $bundlesCollection->add(new Bundle(
                                                    $bundles->doctrine->name,
                                                    $bundles->doctrine->namespace
                                                ));
                if ($input->getOption('doctrine-migration')) {
                    $bundlesCollection->add(new Bundle(
                                                        $bundles->doctrinemigrations->name,
                                                        $bundles->doctrinemigrations->namespace
                                                    ));
                }
            }
            if ('propel' === $input->getOption('orm')) {
                $bundlesCollection->add(new Bundle(
                                                    $bundles->propel->name,
                                                    $bundles->propel->namespace
                                                ));
            }
        }
        if ('mongodb' === $input->getOption('odm')) {
            $bundlesCollection->add(new Bundle(
                                                $bundles->doctrinemongodb->name,
                                                $bundles->doctrinemongodb->namespace
                                            ));
        }

        if ($config_user = $config->bundles->user) {
            $bundlesCollection = $this->addCustomBundlesToCollection($bundlesCollection, $config_user);
        }

        $app = $input->getArgument('app');
        $vendor = $input->getArgument('vendor');
        $bundlesCollection->add(new Bundle($app, sprintf('%s\%sBundle', $vendor, $app)));

        return $bundlesCollection;
    }

    /**
     * Add custom bundles to collection
     *
     * @param $bundlesCollection
     * @param $custom_config
     */
    private function addCustomBundlesToCollection($bundlesCollection, $config_user)
    {
        foreach ($config_user as $config) {
            foreach ($config as $bundle) {
                if (!$name = @$bundle->name) {
                    throw new \RuntimeException(sprintf("The parameter name on bundle is not defined\n"));
                }
                if (!$namespace = @$bundle->namespace) {
                    throw new \RuntimeException(sprintf("The parameter namespace on bundle is not defined\n"));
                }
                $bundlesCollection->add(new Bundle(
                                                    $name,
                                                    $namespace,
                                                    $bundle->config
                                                ));
            }
        }

        return $bundlesCollection;
    }

    /**
     * Generate Custom Bundle Configuration
     * @param $bundlesCollection
     */
    private function generateCustomConfigBundles($bundles)
    {
        $custom = '';
        foreach ($bundles->getCollection() as $bundle) {
            if (null !== $bundle->getConfig()) {
                $custom .= $bundle->getConfig() ."\n";
            }
        }

        return $custom;
    }

    /**
     * Generate Namespace collection
     *
     * @param $input
     */
    private function generateNamespacesCollection($input, $config)
    {
        $ns = $config->namespaces->installer;
        $nsCollection = new NspaceCollection();
        $nsCollection->add(new Nspace(
                                        $ns->symfony->name,
                                        $ns->symfony->path
                                    ));
        $nsCollection->add(new Nspace(
                                        $input->getArgument('vendor'),
                                        'src'
                                    ));
        if (('doctrine' === $input->getOption('orm')) || ('mongodb' === $input->getOption('odm'))) {
            $nsCollection->add(new Nspace(
                                            $ns->doctrinecommon->name,
                                            $ns->doctrinecommon->path
                                        ));
	}
        if ('doctrine' === $input->getOption('orm')) {
            if ($input->getOption('doctrine-fixtures')) {
                $nsCollection->add(new Nspace(
                                                $ns->doctrinedatafixtures->name,
                                                $ns->doctrinedatafixtures->path
                                            ));
            }
            if ($input->getOption('doctrine-migration')) {
                $nsCollection->add(new Nspace(
                                                $ns->doctrinemigrations->name,
                                                $ns->doctrinemigrations->path
                                            ));
            }
        }
        if ('mongodb' === $input->getOption('odm')) {
            $nsCollection->add(new Nspace(
                                            $ns->doctrinemongodb->name,
                                            $ns->doctrinemongodb->path
                                        ));
            $nsCollection->add(new Nspace(
                                            $ns->doctrinemongodbodm->name,
                                            $ns->doctrinemongodbodm->path
                                        ));
        }
        if ('doctrine' === $input->getOption('orm')) {
            $nsCollection->add(new Nspace(
                                            $ns->doctrinedbal->name,
                                            $ns->doctrinedbal->path
                                        ));
            $nsCollection->add(new Nspace(
                                            $ns->doctrine->name,
                                            $ns->doctrine->path
                                        ));
        }
        if ('propel' === $input->getOption('orm')) {
            $nsCollection->add(new Nspace(
                                            $ns->propel->name,
                                            $ns->propel->path
                                        ));
        }
        if ($input->getOption('assetic')) {
            $nsCollection->add(new Nspace(
                                            $ns->assetic->name,
                                            $ns->assetic->path
                                        ));
        }
        $nsCollection->add(new Nspace(
                                        $ns->monolog->name,
                                        $ns->monolog->path
                                    ));

        if ($config_user = $config->namespaces->user) {
            $nsCollection = $this->addCustomNamespacesToCollection($nsCollection, $config_user);
        }
        
        return $nsCollection;
    }

    /**
     * Add custom namespaces to collection
     *
     * @param $nsCollection
     * @param $custom_config
     */
    private function addCustomNamespacesToCollection($nsCollection, $config_user)
    {
        foreach ($config_user as $config) {
            foreach ($config as $namespace) {
                if (!$name = @$namespace->name) {
                    throw new \RuntimeException(sprintf("The parameter name on namespace is not defined\n"));
                }
                if (!$path = @$namespace->path) {
                    throw new \RuntimeException(sprintf("The parameter path on namespace is not defined\n"));
                }
                $nsCollection->add(new Nspace(
                                                $name,
                                                $path
                                            ));
            }
        }

        return $nsCollection;
    }

    /**
     * Generate Prefix collection
     *
     * @param $input
     */
    private function generatePrefixesCollection($input, $config)
    {
        $prefixes = $config->prefixes->installer;
        $prefixCollection = new PrefixCollection();
        if ('twig' === $input->getOption('template-engine')) {
            $prefixCollection->add(new Prefix(
                                                $prefixes->twigextensions->name,
                                                $prefixes->twigextensions->path
                                            ));
            $prefixCollection->add(new Prefix(
                                                $prefixes->twig->name,
                                                $prefixes->twig->path
                                            ));
        }
        if ($input->getOption('swiftmailer')) {
            $prefixCollection->add(new Prefix(
                                                $prefixes->swiftmailer->name,
                                                $prefixes->swiftmailer->path
                                            ));
        }

        if ($config_user = $config->prefixes->user) {
            $prefixCollection = $this->addCustomPrefixesToCollection($prefixCollection, $config_user);
        }

        return $prefixCollection;
    }

    /**
     * Add custom prefixes to collection
     *
     * @param $prefixCollection
     * @param $custom_config
     */
    private function addCustomPrefixesToCollection($prefixCollection, $config_user)
    {
        foreach ($config_user as $config) {
            foreach ($config as $prefix) {
                if (!$name = @$prefix->name) {
                    throw new \RuntimeException(sprintf("The parameter name on prefix is not defined\n"));
                }
                if (!$path = @$prefix->path) {
                    throw new \RuntimeException(sprintf("The parameter path on prefix is not defined\n"));
                }
                $prefixCollection->add(new Prefix(
                                                    $name,
                                                    $path
                                                ));
            }
        }

        return $prefixCollection;
    }

    /**
     * Generate Repository collection
     *
     * @param $input
     */
    private function getRepositoriesCollection($input, $config)
    {
        $repos = $config->repositories->installer;
        $reposCollection = new RepositoryCollection();
        $reposCollection->add(new Repository(
                                                $repos->symfony->source,
                                                $repos->symfony->target,
                                                $this->typeOfElement($repos->symfony->revision),
                                                $repos->symfony->revision
                                            ));
        $reposCollection->add(new Repository(
                                                $repos->monolog->source,
                                                $repos->monolog->target,
                                                $this->typeOfElement($repos->monolog->revision),
                                                $repos->monolog->revision
                                            ));
        if ($input->getOption('swiftmailer')) {
            $reposCollection->add(new Repository(
                                                    $repos->swiftmailer->source,
                                                    $repos->swiftmailer->target,
                                                    $this->typeOfElement($repos->swiftmailer->revision),
                                                    $repos->swiftmailer->revision
                                                ));
        }
        if ($input->getOption('assetic')) {
            $reposCollection->add(new Repository(
                                                    $repos->assetic->source,
                                                    $repos->assetic->target,
                                                    $this->typeOfElement($repos->assetic->revision),
                                                    $repos->assetic->revision
                                                ));
        }
        if ('twig' === $input->getOption('template-engine')) {
            $reposCollection->add(new Repository(
                                                    $repos->twig->source,
                                                    $repos->twig->target,
                                                    $this->typeOfElement($repos->twig->revision),
                                                    $repos->twig->revision
                                                ));
            $reposCollection->add(new Repository(
                                                    $repos->twigextensions->source,
                                                    $repos->twigextensions->target,
                                                    $this->typeOfElement($repos->twigextensions->revision),
                                                    $repos->twigextensions->revision
                                                ));
        }
        if ('doctrine' === $input->getOption('orm')) {
            $reposCollection->add(new Repository(
                                                    $repos->doctrine->source,
                                                    $repos->doctrine->target,
                                                    $this->typeOfElement($repos->doctrine->revision),
                                                    $repos->doctrine->revision
                                                ));
            $reposCollection->add(new Repository(
                                                    $repos->doctrinedbal->source,
                                                    $repos->doctrinedbal->target,
                                                    $this->typeOfElement($repos->doctrinedbal->revision),
                                                    $repos->doctrinedbal->revision
                                                ));
            if ($input->getOption('doctrine-fixtures')) {
                $reposCollection->add(new Repository(
                                                    $repos->doctrinedatafixtures->source,
                                                    $repos->doctrinedatafixtures->target,
                                                    $this->typeOfElement($repos->doctrinedatafixtures->revision),
                                                    $repos->doctrinedatafixtures->revision
                                                    ));
            }
            if ($input->getOption('doctrine-migration')) {
                $reposCollection->add(new Repository(
                                                    $repos->doctrinemigrations->source,
                                                    $repos->doctrinemigrations->target,
                                                    $this->typeOfElement($repos->doctrinemigrations->revision),
                                                    $repos->doctrinemigrations->revision
                                                    ));
            }
        }
        if (('doctrine' === $input->getOption('orm')) || ('mongodb' === $input->getOption('odm'))) {
            $reposCollection->add(new Repository(
                                                    $repos->doctrinecommon->source,
                                                    $repos->doctrinecommon->target,
                                                    $this->typeOfElement($repos->doctrinecommon->revision),
                                                    $repos->doctrinecommon->revision
                                                ));
        }
        if ('mongodb' === $input->getOption('odm')) {
            $reposCollection->add(new Repository(
                                                    $repos->doctrinemongodb->source,
                                                    $repos->doctrinemongodb->target,
                                                    $this->typeOfElement($repos->doctrinemongodb->revision),
                                                    $repos->doctrinemongodb->revision
                                                ));
            $reposCollection->add(new Repository(
                                                    $repos->doctrinemongodbodm->source,
                                                    $repos->doctrinemongodbodm->target,
                                                    $this->typeOfElement($repos->doctrinemongodbodm->revision),
                                                    $repos->doctrinemongodbodm->revision
                                                ));
        }
        if ('propel' === $input->getOption('orm')) {
            $reposCollection->add(new Repository(
                                                    $repos->propelbundle->source,
                                                    $repos->propelbundle->target,
                                                    $this->typeOfElement($repos->propelbundle->revision),
                                                    $repos->propelbundle->revision
                                                ));
            $reposCollection->add(new Repository(
                                                    $repos->phing->source,
                                                    $repos->phing->target,
                                                    $this->typeOfElement($repos->phing->revision),
                                                    $repos->phing->revision
                                                ));
            $reposCollection->add(new Repository(
                                                    $repos->propel->source,
                                                    $repos->propel->target,
                                                    $this->typeOfElement($repos->propel->revision),
                                                    $repos->propel->revision
                                                ));
        }

        if ($config_user = $config->repositories->user) {
            $reposCollection = $this->addCustomRepositoriesToCollection($reposCollection, $config_user);
        }

        return $reposCollection->get();
    }

    /**
     * Add custom repositories to collection
     *
     * @param $reposCollection
     * @param $custom_config
     */
    private function addCustomRepositoriesToCollection($reposCollection, $config_user)
    {
        foreach ($config_user as $config) {
            foreach ($config as $repository) {
                if (!$source = @$repository->source) {
                    throw new \RuntimeException(sprintf("The parameter source on repository is not defined\n"));
                }
                if (!$target = @$repository->target) {
                    throw new \RuntimeException(sprintf("The parameter target on repository is not defined\n"));
                }
                if (!$revision = @$repository->revision) {
                    throw new \RuntimeException(sprintf("The parameter revision on repository is not defined\n"));
                }
                $reposCollection->add(new Repository(
                                                        $source,
                                                        $target,
                                                        $this->typeOfElement($revision),
                                                        $revision
                                                    ));
            }
        }

        return $reposCollection;
    }

    /**
     * Install repository
     *
     * @param $repositories
     * @param $input
     * @param $output
     */
    private function installRepositories($repositories, $input, $output)
    {
        chdir($input->getArgument('path'));
        $path = getcwd();
        $output->writeln(' > <info>Git init</info>');
        exec('git init');
        foreach ($repositories as $repository) {
            $gitcommand = sprintf('git submodule add %s://%s %s',
                                            $input->getOption('protocol'),
                                            $repository->getSource(),
                                            $repository->getTarget()
                                            );
            $output->writeln(sprintf(' > <info>Git %s</info>', $repository->getSource()));
            exec($gitcommand);

            $targetPath = $path.'/'.$repository->getTarget();
            chdir($targetPath);

            $output->writeln(sprintf(' > <comment>Git revision %s</comment>', $repository->getRevision()));
            if ('tag' === $repository->getType()) {
                $gitcommand = sprintf('git fetch origin tag %s', $repository->getRevision());
                exec($gitcommand);
            } else {
                if ('master' !== $repository->getRevision()) {
                    $gitcommand = sprintf('git checkout %s', $repository->getRevision());
                    exec($gitcommand);
                }
            }

            chdir($path);
        }

        exec('git submodule init');
    }

    /**
     * Find and replace
     *
     * @param $input
     * @param $bundles
     * @param $registerNamespaces
     * @param $registerPrefixes
     */
    private function findAndReplace($input, $bundles, $registerNamespaces, $registerPrefixes, $customConfig)
    {
        $twig_config = ('twig' === $input->getOption('template-engine')) ? $this->loadConfigFile('twig') : '';
        $assetic_config = ($input->getOption('assetic')) ? $this->loadConfigFile('assetic') : '';
        $doctrine_config = ('doctrine' === $input->getOption('orm')) ? $this->loadConfigFile('doctrine') : '';
        $doctrine_config = str_replace('{{ app }}', $input->getArgument('app'), $doctrine_config);
        $propel_config = ('propel' === $input->getOption('orm')) ? $this->loadConfigFile('propel') : '';
        $swift_config = ($input->getOption('swiftmailer')) ? $this->loadConfigFile('swiftmailer') : '';
        $routing = ($input->getOption('controller')) ? $this->loadConfigFile('routing') : '';
        $routing = str_replace('{{ app }}', $input->getArgument('app'), $routing);

        Mustache::renderDir($input->getArgument('path'), array(
            'namespace' => $input->getArgument('vendor'),
            'appname' => $input->getArgument('app'),
            'controller' => $input->getOption('controller'),
            'session_start' => $input->getOption('session-start') ? 'true' : 'false',
            'session_name'  => $input->getOption('session-name'),
            'template_engine' => $input->getOption('template-engine'),
            'routing' => $routing,
            'bundles' => $bundles,
            'registerNamespaces' => $registerNamespaces,
            'registerPrefixes' => $registerPrefixes,
            'twig' => $twig_config,
            'assetic' => $assetic_config,
            'doctrine' => $doctrine_config,
            'propel' => $propel_config,
            'swiftmailer' => $swift_config,
            'custom' => $customConfig
        ));
    }

    /**
     * Load config file
     *
     * @param $file
     */
    private function loadConfigFile($file)
    {
        return file_get_contents(__DIR__.'/../Resources/Installer/Config/'.$file.'.txt');
    }

    /**
     * Load profile file
     *
     * @param $file
     */
    private function loadProfileFile($profile)
    {
        $file = __DIR__ .sprintf('/../Resources/Profile/%s.xml', $profile);

        if (!function_exists('simplexml_load_file')) {
            throw new \RuntimeException("The simplexml extension is not installed\n");
        }
        if (!file_exists($file)) {
            throw new \RuntimeException(sprintf("The file %s on path %s does not exist\n",
                basename($file),
                dirname($file)
            ));
        }

        return simplexml_load_file($file);
    }

    /**
     * Get attributes
     *
     * @param $element
     * @param $type
     */
    private function typeOfElement($element)
    {
        $type = null;
        foreach ($element->attributes() as $key => $value) {
            if ('type' === $key) {
                $type = (string) $value;
            }
        }
        
        if ((is_null($type)) || (!in_array($type, array('branch', 'tag')))) {
            throw new \RuntimeException("The type on revision tag is not correct");
        }

        return $type;
    }
}
