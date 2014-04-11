<?php
/**
 * Created by PhpStorm.
 * User: andrew
 * Date: 09.04.14
 * Time: 9:10
 * Author: Kluev Andrew
 * Contact: Kluev.Andrew@gmail.com
 */
namespace KA\SonataAdminJMSTranslationBundle\Twig;

use KA\SonataAdminJMSTranslationBundle\Git\Manager;

/**
 * Class KA\SonataAdminJMSTranslationBundle\Twig\GitExtension
 */
class GitExtension extends \Twig_Extension
{
    /**
     * @var Manager;
     */
    protected $manger;

    /**
     * @param Manager $manger
     */
    public function __construct(Manager $manger)
    {
        $this->manger = $manger;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'ka_sonata_admin_jms_translation_git';
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions()
    {
        return [
            new \Twig_SimpleFunction('ka_is_git_available', [$this->manger, 'isEnabled']),
            new \Twig_SimpleFunction('ka_is_git_version', [$this->manger, 'version']),
            new \Twig_SimpleFunction('ka_is_git_initialized', [$this->manger, 'gitInitialized']),
            new \Twig_SimpleFunction(
                'ka_git_status',
                function ($directory, array $options = [], $returnAsArray = true) {
                    $result = $this->manger->status($directory, $options, $returnAsArray);

                    return $returnAsArray ? $result : nl2br($result);
                },
                [
                    'is_safe' => ['html']
                ]
            ),
            new \Twig_SimpleFunction(
                'ka_git_branch',
                function ($directory, $branch = '', array $options = [], $returnAsArray = true) {
                    $result = $this->manger->branch($directory, $branch, $options, $returnAsArray);

                    return $returnAsArray ? $result : nl2br($result);
                },
                [
                    'is_safe' => ['html']
                ]
            ),
            new \Twig_SimpleFunction(
                'ka_git_branch_list',
                function ($directory, $returnAsArray = true) {
                    $result = $this->manger->branchList($directory, $returnAsArray);

                    return $returnAsArray ? $result : nl2br($result);
                },
                [
                    'is_safe' => ['html']
                ]
            ),
            new \Twig_SimpleFunction('ka_is_git_current_branch', [$this->manger, 'branchCurrent']),
            new \Twig_SimpleFunction('ka_is_git_history', [$this->manger, 'history']),
            new \Twig_SimpleFunction('ka_is_git_diff', [$this->manger, 'diff']),

        ];
    }
}
 