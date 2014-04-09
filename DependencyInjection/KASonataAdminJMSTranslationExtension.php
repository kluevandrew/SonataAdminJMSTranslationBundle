<?php

namespace KA\SonataAdminJMSTranslationBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * Class KA\SonataAdminJMSTranslationBundle\DependencyInjection\SeoSASonataAdminJMSTranslationExtension
 */
class KASonataAdminJMSTranslationExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $remoteRepoAddress = null;
        $remoteRepoPassword = null;
        if (isset($config['remote_repository'])) {
            $remoteRepoAddress = $config['remote_repository']['address'];
            $remoteRepoPassword = $config['remote_repository']['password'];
        }
        $container->setParameter('ka_sonata_admin_jms_translation.remote_repository.address', $remoteRepoAddress);
        $container->setParameter('ka_sonata_admin_jms_translation.remote_repository.password', $remoteRepoPassword);
    }
}
