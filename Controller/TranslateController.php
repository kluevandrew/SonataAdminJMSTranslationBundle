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
use Symfony\Component\HttpFoundation\Request;

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
        $config = $request->query->get('config') ?: reset($configs);

        if (!$config) {
            throw new RuntimeException('You need to configure at least one config under "jms_translation.configs".');
        }

        $translationsDir = $this->configFactory->getConfig($config, 'en')->getTranslationsDir();
        $files = FileUtils::findTranslationFiles($translationsDir);
        if (empty($files)) {
            throw new RuntimeException('There are no translation files for this config, please run the translation:extract command first.');
        }

        $domains = array_keys($files);
        $domain = $request->query->get('domain') ?: reset($domains);
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
            'selectedConfig' => $config,
            'configs' => $configs,
            'selectedDomain' => $domain,
            'domains' => $domains,
            'selectedLocale' => $locale,
            'locales' => $locales,
            'format' => $files[$domain][$locale][0],
            'newMessages' => $newMessages,
            'existingMessages' => $existingMessages,
            'alternativeMessages' => $alternativeMessages,
            'isWriteable' => is_writeable($files[$domain][$locale][1]),
            'file' => (string) $files[$domain][$locale][1],
            'sourceLanguage' => $this->sourceLanguage,
            'base_template'  => $this->getBaseTemplate($request),
            'admin_pool'     => $this->container->get('sonata.admin.pool'),
            'blocks'         => $this->container->getParameter('sonata.admin.configuration.dashboard_blocks'),
        ];
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
}
 