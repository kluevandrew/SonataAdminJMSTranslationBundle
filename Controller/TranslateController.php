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

use Alchemy\Zippy\Exception\FormatNotSupportedException;
use Alchemy\Zippy\Exception\NoAdapterOnPlatformException;
use Alchemy\Zippy\Zippy;
use JMS\TranslationBundle\Exception\RuntimeException;
use JMS\TranslationBundle\Translation\Config;
use JMS\TranslationBundle\Util\FileUtils;
use JMS\DiExtraBundle\Annotation as DI;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as ControllerConfiguration;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Exception\ProcessFailedException;
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
     * @throws \InvalidArgumentException
     * @throws \JMS\TranslationBundle\Exception\InvalidArgumentException
     * @throws \JMS\TranslationBundle\Exception\RuntimeException
     */
    public function indexAction(Request $request)
    {
        $configs = $this->configFactory->getNames();
        $config  = $request->get('config') ? : reset($configs);

        if (!$config) {
            throw new RuntimeException('You need to configure at least one config under "jms_translation.configs".');
        }

        /** @var Config $configuration */
        $configuration   = $this->configFactory->getConfig($config, 'any');
        $translationsDir = $configuration->getTranslationsDir();
        $files           = FileUtils::findTranslationFiles($translationsDir);
        if (empty($files)) {
            throw new RuntimeException(
                'There are no translation files for this config, please run the translation:extract command first.'
            );
        }
        $downloadError        = '';
        $supportedArchFormats = $this->getSupportedArchiveFormats();
        if ($archiveFormat = $request->get('archive')) {
            if (!in_array($archiveFormat, $supportedArchFormats)) {
                $downloadError = sprintf(
                    'Unavailable archive format, available! Supports: %s',
                    implode(', ', $supportedArchFormats)
                );
            } else {
                try {
                    return $this->sendArchive($config, $archiveFormat, $files);
                } catch (\Exception $e) {
                    // Todo: Is a potencial security risk
                    $downloadError = $e->getMessage();
                }
            }
        }

        $domains = array_keys($files);
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
            'currentDir'           => $translationsDir,
            'selectedConfig'       => $config,
            'selectedDomain'       => $domain,
            'selectedLocale'       => $locale,
            'configs'              => $configs,
            'domains'              => $domains,
            'locales'              => $locales,
            'format'               => $files[$domain][$locale][0],
            'newMessages'          => $newMessages,
            'existingMessages'     => $existingMessages,
            'alternativeMessages'  => $alternativeMessages,
            'isWriteable'          => is_writeable($files[$domain][$locale][1]),
            'file'                 => (string) $files[$domain][$locale][1],
            'sourceLanguage'       => $this->sourceLanguage,
            'base_template'        => $this->getBaseTemplate($request),
            'admin_pool'           => $this->container->get('sonata.admin.pool'),
            'blocks'               => $this->container->getParameter('sonata.admin.configuration.dashboard_blocks'),
            'supportedArchFormats' => $supportedArchFormats,
            'downloadError'        => $downloadError
        ];
    }

    /**
     * @param Request $request
     * @param string  $config
     * @param string  $locale
     *
     * @ControllerConfiguration\Route("/configs/{config}locales/{locale}/upload", name="jms_translation_upload", options = {"i18n" = false})
     *
     * @return RedirectResponse
     * @throws \RuntimeException
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException
     * @throws \InvalidArgumentException
     */
    public function uploadAction(Request $request, $config, $locale)
    {
        $translationsDir = $this->configFactory->getConfig($config, $locale)->getTranslationsDir();

        $archive = $request->files->get('archive');
        if (!$archive instanceof UploadedFile) {
            throw new RuntimeException('Bad file.');
        }

        $manager = $this->getGitManager();
        if ($manager->isEnabled() && $manager->gitInitialized($translationsDir) && $request->get('create_new_branch')) {
            $branchName = sprintf('upload_file_%s', date('Y_m_d_h_i'));
            $manager->branch($translationsDir, $branchName);
            $manager->checkout($translationsDir, $branchName);
        }

        $archivePath = $archive->getRealPath() . '.' . $archive->getClientOriginalName();
        move_uploaded_file($archive->getRealPath(), $archivePath);

        $archive = $this->getZippy()->open($archivePath);
        foreach ($archive->getMembers() as $member) {
            /** @var \Alchemy\Zippy\Archive\Member $member */

            $extractTo = $translationsDir . DIRECTORY_SEPARATOR . $member->getLocation();
            if (file_exists($extractTo)) {
                unlink($extractTo);
            }
            $member->extract($translationsDir);
        }

        return new RedirectResponse($request->headers->get('referer'));
    }

    /**
     * @param string $config
     *
     * @ControllerConfiguration\Route("/configs/{config}/git/", name="ka_sonataadminjmstranslation_translate_git_info")
     * @ControllerConfiguration\Template
     *
     * @return array
     * @throws \JMS\TranslationBundle\Exception\RuntimeException
     */
    public function gitAction($config)
    {
        if (!$config) {
            throw new RuntimeException('You need to configure at least one config under "jms_translation.configs".');
        }

        /** @var Config $configuration */
        $configuration = $this->configFactory->getConfig($config, 'any');

        return [
            'selectedConfig' => $config,
            'currentDir'     => $configuration->getTranslationsDir(),
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
        $configuration = $builder->setLocale('any')->getConfig();
        $directory     = $configuration->getTranslationsDir();

        try {
            switch ($command) {
                case 'init':
                    $manager->init($directory);
                    break;
                case 'commit':
                    $message = trim($request->get('commit_message', ''));
                    $manager->commit($directory, $message);
                    break;
                case 'reset':
                    $to      = trim($request->get('to', ''));
                    $options = $request->get('options', []);
                    $manager->reset($directory, $to, $options);
                    break;
                case 'checkout':
                    $branch  = trim($request->get('branch', ''));
                    $options = $request->get('options', []);
                    $manager->checkout($directory, $branch, $options);
                    break;
                case 'branch':
                    $branch  = trim($request->get('branch', ''));
                    $options = $request->get('options', []);
                    $manager->branch($directory, $branch, $options);
                    break;
                case 'merge':
                    $revision1 = trim($request->get('revision1', ''));
                    $revision2 = trim($request->get('revision2', ''));
                    $manager->merge($directory, $revision1, $revision2);
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown command');
            }
        } catch (\InvalidArgumentException $e) {
            $msg = /** @Ignore */
                $this->getTranslator()->trans($e->getMessage());

            return new Response($msg, 400);
        } catch (ProcessFailedException $e) {
            $msg = /** @Ignore */
                $this->getTranslator()->trans($e->getMessage());

            return new Response($msg, 500);
        }


        return new RedirectResponse(
            $this->getRouter()->generate(
                'ka_sonataadminjmstranslation_translate_git_info',
                [
                    'config' => $config
                ]
            )
        );
    }

    /**
     * @return \Sonata\AdminBundle\Admin\Pool
     */
    protected function getAdminPool()
    {
        return $this->container->get('sonata.admin.pool');
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
     * @return Zippy
     */
    protected function getZippy()
    {
        return Zippy::load();
    }

    /**
     * @return array
     */
    protected function getSupportedArchiveFormats()
    {
        $formats          = ['zip', 'tar.gz', 'tar.bz2'];
        $supportedFormats = [];

        $zippy = $this->getZippy();
        foreach ($formats as $format) {
            try {
                $zippy->getAdapterFor($format);
                $supportedFormats[] = $format;
            } catch (FormatNotSupportedException $e) {
            } catch (NoAdapterOnPlatformException $e) {
            } catch (\RuntimeException $e) {
            }
        }

        return $supportedFormats;
    }

    /**
     * @param string $archName
     * @param string $format
     * @param array  $files
     *
     * @return Response
     * @throws \Alchemy\Zippy\Exception\RuntimeException
     */
    protected function sendArchive($archName, $format, array $files)
    {
        $formats = [
            'zip'     => 'application/zip, application/octet-stream',
            'tar.gz'  => 'application/x-tar, application/x-tar-gz',
            'tar.bz2' => 'application/x-tar, application/x-bzip2',
        ];

        $realFiles = [];
        foreach ($files as $locales) {
            foreach ($locales as $data) {
                /** @var \SplFileInfo $splFile */
                $splFile     = $data[1];
                $realFiles[] = fopen($splFile->getRealPath(), 'r');
            }
        }

        $archName = $archName . '.' . $format;
        $archPath = sprintf('%s/%s', sys_get_temp_dir(), $archName);

        $this->getZippy()->create($archPath, $realFiles, true, $format);

        $response = new Response(file_get_contents($archPath), 200);
        $response->headers->set('Content-Type', $formats[$format]);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $archName . '"');
        $response->headers->set('Pragma', "no-cache");
        $response->headers->set('Expires', "0");
        $response->headers->set('Content-Transfer-Encoding', "binary");
        $response->headers->set('Content-Length', filesize($archPath));

        @unlink($archPath);

        return $response;
    }

    /**
     * @return \Symfony\Component\Security\Core\SecurityContextInterface
     */
    protected function getSecurityContext()
    {
        return $this->container->get('security.context');
    }

    /**
     * @return \KA\SonataAdminJMSTranslationBundle\Git\Manager
     */
    protected function getGitManager()
    {
        return $this->container->get('ka_sonata_admin_jms_translation.git.manager');
    }

    /**
     * @return \Symfony\Component\Translation\TranslatorInterface
     */
    protected function getTranslator()
    {
        return $this->container->get('translator');
    }

    /**
     * @return \Symfony\Component\Routing\RouterInterface
     */
    protected function getRouter()
    {
        return $this->container->get('router');
    }
}
 