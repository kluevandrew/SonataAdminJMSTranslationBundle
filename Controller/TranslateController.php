<?php
/**
 * Created by PhpStorm.
 * User: andrew
 * Date: 20.03.14
 * Time: 13:49
 * Author: Kluev Andrew
 * Contact: Kluev.Andrew@gmail.com
 */
namespace KA\SonataAdminJMSTranslationBundle\Controller;

use JMS\TranslationBundle\Exception\RuntimeException;
use JMS\TranslationBundle\Util\FileUtils;
use JMS\DiExtraBundle\Annotation as DI;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as ControllerConfiguration;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\SecurityContextInterface;

/**
 * Class KA\SonataAdminJMSTranslationBundle\Controller\TranslateController
 */
class TranslateController
{
    /**
     * @var \JMS\TranslationBundle\Translation\ConfigFactory
     *
     * @DI\Inject("jms_translation.config_factory")
     */
    protected $configFactory;

    /**
     * @var \JMS\TranslationBundle\Translation\LoaderManager
     *
     * @DI\Inject("jms_translation.loader_manager")
     */
    protected $loader;

    /**
     * @var \Symfony\Component\DependencyInjection\ContainerInterface
     * @DI\Inject("service_container")
     */
    protected $container;

    /**
     * @var string
     *
     * @DI\Inject("%jms_translation.source_language%")
     */
    protected $sourceLanguage;


    /**
     * @param Request $request
     *
     * @ControllerConfiguration\Route("/", name="jms_translation_index", options = {"i18n" = false})
     * @ControllerConfiguration\Template
     *
     * @return array
     * @throws \JMS\TranslationBundle\Exception\RuntimeException
     */
    public function indexAction(Request $request)
    {
        $configs = $this->configFactory->getNames();
        $config  = $request->query->get('config') ? : reset($configs);

        if (!$config) {
            throw new RuntimeException('You need to configure at least one config under "jms_translation.configs".');
        }

        $translationsDir = $this->configFactory->getConfig($config, 'en')->getTranslationsDir();
        $files           = FileUtils::findTranslationFiles($translationsDir);
        if (empty($files)) {
            throw new RuntimeException('There are no translation files for this config, please run the translation:extract command first.');
        }

        $domains = array_keys($files);
        $domain  = $request->query->get('domain') ? : reset($domains);
        if ((!$domain = $request->query->get('domain')) || !isset($files[$domain])) {
            $domain = reset($domains);
        }

        $locales = array_keys($files[$domain]);

        natsort($locales);

        if ((!$locale = $request->query->get('locale')) || !isset($files[$domain][$locale])) {
            $locale = reset($locales);
        }

        $catalogue = $this->loader->loadFile(
            $files[$domain][$locale][1]->getPathName(),
            $files[$domain][$locale][0],
            $locale,
            $domain
        );

        // create alternative messages
        // TODO: We should probably also add these to the XLIFF file for external translators,
        //       and the specification already supports it
        $alternativeMessages = array();
        foreach ($locales as $otherLocale) {
            if ($locale === $otherLocale) {
                continue;
            }

            $altCatalogue = $this->loader->loadFile(
                $files[$domain][$otherLocale][1]->getPathName(),
                $files[$domain][$otherLocale][0],
                $otherLocale,
                $domain
            );
            foreach ($altCatalogue->getDomain($domain)->all() as $id => $message) {
                $alternativeMessages[$id][$otherLocale] = $message;
            }
        }

        $newMessages = $existingMessages = array();
        foreach ($catalogue->getDomain($domain)->all() as $id => $message) {
            if ($message->isNew()) {
                $newMessages[$id] = $message;
                continue;
            }

            $existingMessages[$id] = $message;
        }

        return [
            'selectedConfig'      => $config,
            'configs'             => $configs,
            'selectedDomain'      => $domain,
            'domains'             => $domains,
            'selectedLocale'      => $locale,
            'locales'             => $locales,
            'format'              => $files[$domain][$locale][0],
            'newMessages'         => $newMessages,
            'existingMessages'    => $existingMessages,
            'alternativeMessages' => $alternativeMessages,
            'isWriteable'         => is_writeable($files[$domain][$locale][1]),
            'file'                => (string) $files[$domain][$locale][1],
            'sourceLanguage'      => $this->sourceLanguage,
            'base_template'       => $this->getBaseTemplate($request),
            'admin_pool'          => $this->container->get('sonata.admin.pool'),
            'blocks'              => $this->container->getParameter('sonata.admin.configuration.dashboard_blocks'),
            'config'              => $this->configFactory->getConfig($config, $locale),
            'remote_repo'         => $this->container->getParameter('ka_sonata_admin_jms_translation.remote_repository.address'),
        ];
    }

    /**
     * @param Request $request
     * @param string  $config
     * @param string  $command
     *
     * @ControllerConfiguration\Route("/configs/{config}/git/{command}/", name="jms_translation_git_exec", options = {"i18n" = false})
     *
     * @return RedirectResponse
     * @throws \RuntimeException
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException
     * @throws \InvalidArgumentException
     */
    public function gitExecAction(Request $request, $config, $command)
    {
        if (false == $this->getSecurityContext()->isGranted('ROLE_SUPER_ADMIN')) {
            throw new AccessDeniedException();
        }

        $manager = $this->getGitManager();
        if (!$manager->isEnabled()) {
            throw new \RuntimeException('Git is not available.');
        }

        /** @var \JMS\TranslationBundle\Translation\ConfigBuilder $builder */
        $builder = $this->configFactory->getBuilder($config);
        /** @var \JMS\TranslationBundle\Translation\Config $config */
        $config = $builder->setLocale('any')->getConfig();
        $directory = $config->getTranslationsDir();

        $remoteAddress = $this->container->getParameter('ka_sonata_admin_jms_translation.remote_repository.address');
        $remotePassword = $this->container->getParameter('ka_sonata_admin_jms_translation.remote_repository.password');

        switch ($command) {
            case 'init':
                if (!$manager->init($directory)) {
                    throw new \RuntimeException('An error occurred while exec git init.');
                }
                break;
            case 'commit':
                $message = trim($request->get('commit_message', ''));
                if (!$message) {
                    throw new \InvalidArgumentException('Message can\'t be empty');
                }
                if (!$manager->commit($directory, $message)) {
                    throw new \RuntimeException('An error occurred while exec git commit.');
                }
                break;
            case 'reset':
                $to = trim($request->get('to', ''));
                $options = $request->get('options', []);
                if (!$to) {
                    throw new \InvalidArgumentException('Revision can\'t be empty');
                }
                if (!is_array($options)) {
                    throw new \InvalidArgumentException('Options must be an array');
                }
                if (!$manager->reset($directory, $to, $options)) {
                    throw new \RuntimeException('An error occurred while exec git reset.');
                }
                break;
            case 'checkout':
                $branch = trim($request->get('branch', ''));
                $options = $request->get('options', []);
                if (!$branch) {
                    throw new \InvalidArgumentException('Branch can\'t be empty');
                }
                if (!is_array($options)) {
                    throw new \InvalidArgumentException('Options must be an array');
                }
                if (!$manager->checkout($directory, $branch, $options)) {
                    throw new \RuntimeException('An error occurred while exec git checkout.');
                }
                break;
            case 'branch':
                $branch = trim($request->get('branch', ''));
                $options = $request->get('options', []);
                if (!$branch) {
                    throw new \InvalidArgumentException('Branch can\'t be empty');
                }
                if (!is_array($options)) {
                    throw new \InvalidArgumentException('Options must be an array');
                }
                if ($branch === $manager->branchCurrent($directory)) {
                    throw new \InvalidArgumentException('Can\'t operate with current branch');
                }
                $manager->branch($directory, $branch, $options, true, $returnVar);
                if ($returnVar !== 0) {
                    throw new \RuntimeException('An error occurred while exec git branch.');
                }
                break;
            case 'merge':
                $revision1 = trim($request->get('revision1', ''));
                $revision2 = trim($request->get('revision2', ''));
                if (!$revision1 || !$revision2) {
                    throw new \InvalidArgumentException('Revisions can\'t be empty');
                }
                if ($revision1 === $revision2) {
                    throw new \InvalidArgumentException('Can\'t merge same revisions');
                }
                if (!$manager->merge($directory, $revision1, $revision2)) {
                    throw new \RuntimeException('An error occurred while exec git merge.');
                }
                break;
            case 'push':
                if (!$remoteAddress) {
                    throw new \RuntimeException('Remote repo not exists.');
                }
                if (!$manager->push($directory, $remoteAddress, $remotePassword)) {
                    throw new \RuntimeException('An error occurred while exec git push.');
                }
                break;
            default:
                throw new \InvalidArgumentException('Unknown command');
        }

        return new RedirectResponse($request->headers->get('referer'));
    }

    /**
     * @param Request $request
     *
     * @return null|string
     */
    protected function getBaseTemplate(Request $request)
    {
        if ($request->isXmlHttpRequest()) {
            return $this->getAdminPool()->getTemplate('ajax');
        }

        return $this->getAdminPool()->getTemplate('layout');
    }

    /**
     * @return \Sonata\AdminBundle\Admin\Pool
     */
    protected function getAdminPool()
    {
        return $this->container->get('sonata.admin.pool');
    }

    /**
     * @return \KA\SonataAdminJMSTranslationBundle\Git\Manager
     */
    protected function getGitManager()
    {
        return $this->container->get('ka_sonata_admin_jms_translation.git.manager');
    }

    /**
     * @return \Symfony\Component\Security\Core\SecurityContextInterface
     */
    protected function getSecurityContext()
    {
        return $this->container->get('security.context');
    }
}
 