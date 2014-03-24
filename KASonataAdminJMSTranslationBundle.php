<?php

namespace KA\SonataAdminJMSTranslationBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Class KA\SonataAdminJMSTranslationBundle\SeoSASonataAdminJMSTranslationBundle
 */
class KASonataAdminJMSTranslationBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return 'JMSTranslationBundle';
    }
}
