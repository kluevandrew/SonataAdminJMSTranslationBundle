<?php
/**
 * Created by PhpStorm.
 * User: andrew
 * Date: 20.03.14
 * Time: 13:48
 * Author: Kluev Andrew
 * Contact: Kluev.Andrew@gmail.com
 */
namespace KA\SonataAdminJMSTranslationBundle\Controller;

use JMS\TranslationBundle\Exception\RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use JMS\TranslationBundle\Util\FileUtils;
use Symfony\Component\HttpFoundation\Request;
use JMS\DiExtraBundle\Annotation as DI;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as ControllerConfiguration;

/**
 * Class KA\SonataAdminJMSTranslationBundle\Controller\ApiController
 */
class ApiController
{
    /**
     * @var \Symfony\Component\HttpKernel\Kernel
     * @DI\Inject("kernel")
     */
    protected $kernel;

    /**
     * @var \JMS\TranslationBundle\Translation\ConfigFactory
     *
     * @DI\Inject("jms_translation.config_factory")
     */
    protected $configFactory;

    /**
     * @var \KA\SonataAdminJMSTranslationBundle\Translation\Updater
     *
     * @DI\Inject("ka_sonata_admin_jms_translation.updater")
     */
    protected $updater;

    /**
     * @param Request $request
     * @param string  $config
     * @param string  $domain
     * @param string  $locale
     *
     * @ControllerConfiguration\Route("/configs/{config}/domains/{domain}/locales/{locale}/messages",
     *            name="jms_translation_update_message",
     *            defaults = {"id" = null},
     *            options = {"i18n" = false})
     * @ControllerConfiguration\Method("PUT")
     *
     * @return Response
     * @throws \JMS\TranslationBundle\Exception\RuntimeException
     */
    public function updateMessageAction(Request $request, $config, $domain, $locale)
    {
        $id = $request->query->get('id');

        /** @var \JMS\TranslationBundle\Translation\Config $config */
        $config = $this->configFactory->getConfig($config, $locale);

        $files = FileUtils::findTranslationFiles($config->getTranslationsDir());
        if (!isset($files[$domain][$locale])) {
            throw new RuntimeException(sprintf(
                'There is no translation file for domain "%s" and locale "%s".',
                $domain,
                $locale
            ));
        }

        list($format, $file) = $files[$domain][$locale];

        $this->updater->updateTranslation(
            $file,
            $format,
            $domain,
            $locale,
            $id,
            $request->request->get('message')
        );

        return new Response();
    }

    /**
     * @param Request $request
     * @param string  $config
     * @param string  $domain
     * @param string  $locale
     *
     * @ControllerConfiguration\Route("/configs/{config}/domains/{domain}/locales/{locale}/messages/create",
     *            name="jms_translation_create_message",
     *            options = {"i18n" = false})
     *
     * @ControllerConfiguration\Method("PUT")
     *
     * @return Response
     * @throws \JMS\TranslationBundle\Exception\RuntimeException
     * @throws \InvalidArgumentException
     */
    public function createMessageAction(Request $request, $config, $domain, $locale)
    {
        $id = $request->get('id');
        if (!$id) {
            throw new \InvalidArgumentException('Id can\'t be empty');
        }
        /** @var \JMS\TranslationBundle\Translation\Config $config */
        $config = $this->configFactory->getConfig($config, $locale);


        $files = FileUtils::findTranslationFiles($config->getTranslationsDir());
        if (!isset($files[$domain][$locale])) {
            throw new RuntimeException(sprintf(
                'There is no translation file for domain "%s" and locale "%s".',
                $domain,
                $locale
            ));
        }

        list($format, $file) = $files[$domain][$locale];

        $this->updater->addTranslation(
            $file,
            $format,
            $domain,
            $locale,
            $id,
            $request->request->get('message')
        );

        return new Response();
    }


    /**
     * @param $locale
     *
     * @ControllerConfiguration\Route("/locales/{locale}/cache/clear/", name="jms_translation_clear_cache")
     * @ControllerConfiguration\Method("POST")
     *
     * @return Response
     * @throws \JMS\TranslationBundle\Exception\RuntimeException
     */
    public function clearCacheAction($locale)
    {
        $dir = $this->kernel->getCacheDir() . '/translations/';

        $localeExploded = explode('_', $locale);
        $localePattern  = sprintf('%s/catalogue.*%s*.php', $dir, $localeExploded[0]);
        $files          = glob($localePattern);

        $deleted = true;
        foreach ($files as $file) {
            if (!unlink($file)) {
                $deleted = false;
            }
            $metadata = $file . '.meta';
            if (file_exists($metadata)) {
                unlink($metadata);
            }
        }
        if (!$deleted) {
            throw new RuntimeException;
        }

        return new Response();
    }
}
 