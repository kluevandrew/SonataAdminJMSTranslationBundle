<?php
/**
 * Created by PhpStorm.
 * User: andrew
 * Date: 24.03.14
 * Time: 13:28
 * Author: Kluev Andrew
 * Contact: Kluev.Andrew@gmail.com
 */
namespace KA\SonataAdminJMSTranslationBundle\Translation;

use JMS\TranslationBundle\Translation\Config;
use JMS\TranslationBundle\Translation\ExtractorManager;
use JMS\TranslationBundle\Translation\FileWriter;
use JMS\TranslationBundle\Translation\LoaderManager;
use JMS\TranslationBundle\Translation\Updater as JMSUpdater;
use JMS\TranslationBundle\Model\Message;
use Psr\Log\LoggerInterface;

/**
 * Class KA\SonataAdminJMSTranslationBundle\Translation\Updater
 */
class Updater extends JMSUpdater
{
    /**
     * @var \JMS\TranslationBundle\Translation\LoaderManager
     */
    protected $loader;
    /**
     * @var \JMS\TranslationBundle\Translation\ExtractorManager
     */
    protected $extractor;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;
    /**
     * @var \JMS\TranslationBundle\Translation\FileWriter
     */
    protected $writer;

    /**
     * @param LoaderManager    $loader
     * @param ExtractorManager $extractor
     * @param LoggerInterface  $logger
     * @param FileWriter       $writer
     */
    public function __construct(LoaderManager $loader, ExtractorManager $extractor, LoggerInterface $logger, FileWriter $writer)
    {
        $this->loader = $loader;
        $this->extractor = $extractor;
        $this->logger = $logger;
        $this->writer = $writer;

        parent::__construct($loader, $extractor, $logger, $writer);
    }

    /**
     * @param string $file
     * @param string $format
     * @param string $domain
     * @param string $locale
     * @param string $id
     * @param string $trans
     *
     * @throws \InvalidArgumentException
     */
    public function addTranslation($file, $format, $domain, $locale, $id, $trans)
    {
        /** @var \JMS\TranslationBundle\Model\MessageCatalogue $catalogue */
        $catalogue = $this->loader->loadFile($file, $format, $locale, $domain);

        $message = new Message($id, $domain);
        if ($catalogue->has($message)) {
            throw new \InvalidArgumentException(sprintf('Message with id "%s" in domain "%s" already exists.', $id, $domain));
        }

        $message->setLocaleString($trans);
        $message->setNew(false);
        $catalogue->add($message);

        $this->writer->write($catalogue, $domain, $file, $format);
    }

    /**
     * @param string $file
     * @param string $format
     * @param string $domain
     * @param string $locale
     * @param string $id
     *
     * @throws \InvalidArgumentException
     */
    public function removeTranslation($file, $format, $domain, $locale, $id)
    {
        /** @var \JMS\TranslationBundle\Model\MessageCatalogue $catalogue */
        $catalogue = $this->loader->loadFile($file, $format, $locale, $domain);

        $messages = $catalogue->getDomain($domain)->all();
        if (!isset($messages[$id])) {
            throw new \InvalidArgumentException(sprintf('Message with id "%s" in domain "%s" not found.', $id, $domain));
        }
        unset($messages[$id]);
        $catalogue->getDomain($domain)->replace($messages);

        $this->writer->write($catalogue, $domain, $file, $format);
    }
}
 